# Hotel Booking Sync Application

This is a Laravel-based application designed to synchronize booking data from an external Property Management System (PMS) API into a local database. It provides:

- A console command to fetch and update bookings, guests, rooms, and room types.
- A web UI to view, filter, and manually trigger syncs.
- A dedicated service class (`BookingSyncService`) for clean logic separation.

---

## Features

- **Data Synchronization**: Syncs bookings, guests, rooms, and room types from a remote PMS API.
- **Dedicated Service Layer**: All sync logic is encapsulated in the `BookingSyncService` class under `App\Services` for maintainability and reuse.
- **Full and Incremental Sync**: Can fetch all bookings or only updated ones using an optional `--since` filter (`updated_at.gt`).
- **Robust Error Handling**: Includes transaction-based database operations to ensure data integrity and detailed logging for debugging.
- **API Rate Limiting**: Implements a simple delay between API requests to avoid hitting rate limits.
- **Response Caching**: PMS API responses (booking, guest, room, room type) are cached in Laravel for faster repeated access and reduced API load.
- **Logging Sync Results**: Saves sync metadata for each resource in a `sync_logs` table for auditing and review.
- **Configurable**: Easily configure the PMS API endpoint and credentials.
 **Web UI**: View bookings, guests, rooms, trigger syncs, toggle dark mode.

## Requirements

- PHP >= 8.1
- Composer
- Laravel >= 10.x
- A supported database (e.g., MySQL, PostgreSQL)

## Installation

1. **Clone the repository:**
   ```bash
   git clone <repository-url>
   cd hotel-booking
   ```

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Create your environment file:**
   ```bash
   cp .env.example .env
   ```

4. **Generate an application key:**
   ```bash
   php artisan key:generate
   ```

5. **Configure your database:**
   Update the `DB_*` variables in your `.env` file with your database credentials.

6. **Run database migrations:**
   This will create the necessary tables: `bookings`, `guests`, `rooms`, `room_types`, and `sync_logs`.
   ```bash
   php artisan migrate
   ```

7. **Configure the PMS API:**
   The API connection is configured in `app/Providers/AppServiceProvider.php` using an `Http` macro:

   ```php
   Http::macro('pms', function () {
       return Http::withOptions([
           'verify' => base_path('cacert.pem'),
           'base_uri' => 'https://api.pms.donatix.info/api/',
           // 'headers' => ['Authorization' => 'Bearer YOUR_API_TOKEN'],
       ]);
   });
   ```

## Usage

### Manual Sync

Run the command manually without any filter to fetch **all bookings** from the PMS:

```bash
php artisan sync:bookings
```

👉 This is useful for the **initial full sync**.

You can also pass a custom `--since` timestamp (ISO 8601 format) to only fetch updated bookings (incremental sync):

```bash
php artisan sync:bookings --since="2025-07-20T00:00:00Z"
```

### Scheduled Sync

Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('sync:bookings')->everyFifteenMinutes();
}
```

Make sure to configure your system's cron to run Laravel's scheduler.

---

## Web UI

Visit:

```bash
http://localhost:8000/bookings

### Features:

- ✅ Paginated list (20 per page)
- 📘 Rich info per booking
- 🔄 Sync bookings manually from UI
- 📅 Sync filter: choose `--since` date
- 🌓 Dark mode toggle (top-right corner)

### Screenshot-style layout:
```
📘 Booking ID: 3417 | External ID: EXT-BKG-3417 | Room ID: 223 | Guest IDs: 416,520

📅 Check-in: 2025-09-03 | Check-out: 2025-09-09 | Status: pending | Notes: Early check-out

🏨 Room: ID 223 | Number: 203 | RoomType: Deluxe King | Floor: 2

🛏️ RoomType: ID 303 | RoomType Name: Deluxe King | RoomType Description: Spacious room with king-size bed and city view

👤 Guest ID: 416, Name: Sophia Thomas

👤 Guest ID: 520, Name: Charlotte Robinson
```

---

## UI Diagram

```plaintext
+---------------------------------------------------------+
|                  Hotel Booking Dashboard                |
|---------------------------------------------------------|
| 🔄 [Sync Bookings]  📅 [Filter by Since Date] 🌓 Dark Mode |
|---------------------------------------------------------|
| 📘 Booking #3417                                         |
|  ├── Check-in: 2025-09-03                                |
|  ├── Check-out: 2025-09-09                               |
|  ├── Room: #203 (Floor 2) - Type: Deluxe King            |
|  ├── Guests: Sophia Thomas, Charlotte Robinson           |
|  └── Notes: Early check-out                              |
|---------------------------------------------------------|
| 📘 Booking #3418 ... (next)                              |
|---------------------------------------------------------|
|  ◀ Previous Page   1 2 3 ...  Next ▶                     |
+---------------------------------------------------------+


---

## Web Sync Behavior
BookingWebController::sync(Request $request)
This method is triggered when you click the "Sync Bookings" button in the web UI.

Functionality:

Retrieves an optional since parameter (e.g., from a date filter input).

Queues a background job (SyncBookingsJob) instead of running the sync inline.

Displays a success flash message after dispatching the job.

Redirects the user back to the bookings list.

public function sync(Request $request)
{
    $since = $request->input('since');
    SyncBookingsJob::dispatch($since);
    Session::flash('message', '✅ Booking sync has been queued. It will run shortly.');
    return redirect()->route('bookings.index');
}
✅ This ensures the UI remains responsive, and large syncs do not block the HTTP request.

App\Jobs\SyncBookingsJob
This Laravel queued job handles syncing bookings in the background.

What it does:

Accepts a since date.

Instantiates a BufferedOutput (compatible with the service signature).

Invokes BookingSyncService::syncBookings() using the passed since value and buffered console output.

Logs a confirmation message in the Laravel logs once done.

class SyncBookingsJob implements ShouldQueue
{
    public function __construct(?string $since = null)
    {
        $this->since = $since;
    }

    public function handle(BookingSyncService $syncService): void
    {
        $output = new BufferedOutput();
        $syncService->setDelay(500000);
        $syncService->syncBookings($this->since, $output);
        Log::info('✅ Background sync finished.', ['since' => $this->since]);
    }
}
🔄 This job runs asynchronously via Laravel’s queue system. Be sure to start your queue worker:

bash
php artisan queue:work

```
## API

api.php

Route::post('/sync-bookings', [SyncController::class, 'run']);

Headers:

Content Type/ application/vnd.api+json

Body:

{
  "since": "2025-07-20"
}

Postman:

POST

http://localhost:8000/api/sync-bookings

Example response:

{
    "message": "✅ Booking sync has been queued.",
    "since": "2025-07-20"
}

php artisan queue:work


```
## Service Architecture

All synchronization logic is handled by the `BookingSyncService` class:

```php
namespace App\Services;

class BookingSyncService
{
    public function syncBookings(?string $since, Command $console): void
    {
        // Handles fetching, caching, and storing bookings, rooms, guests, room types
    }

    public function setDelay(int $delay): static
    {
        // Configures API delay to respect rate limiting
    }
}
```

You can call this service manually or inject it into commands/controllers.

The `SyncBookingsCommand` uses this service to run the sync cleanly:

```php
public function __construct(protected BookingSyncService $syncService)
{
    parent::__construct();
    $this->syncService->setDelay(500000);
}
```

## Logging

The command uses two logging systems:

- **Laravel log file**: Located at `storage/logs/laravel.log`
- **Database sync log**: A `sync_logs` table tracks each resource sync

### `logSync()` Helper

```php
protected function logSync($type, $id, $status, $message = null)
{
    SyncLog::create([
        'resource_type' => $type,
        'resource_id'   => $id,
        'status'        => $status,
        'message'       => $message,
    ]);
}
```

## Caching

This sync command uses Laravel's cache to store fetched data for each booking, guest, room, and room type:

- Bookings are cached as `booking:{id}`
- Guests as `guest:{id}`
- Rooms as `room:{id}`
- Room types as `room_type:{id}`

Each cached entry is valid for **1 hour (3600 seconds)** by default.

You can clear the cache using:

```bash
php artisan cache:clear
```

## ✅ Functional Requirements

| Requirement                                | Status | Notes                                                                 |
|--------------------------------------------|--------|-----------------------------------------------------------------------|
| Fetch all bookings from PMS                | ✅     | Supports full sync and filtered sync via `updated_at.gt`              |
| Fetch related room, room type, and guest   | ✅     | Implemented using `GET /rooms/{id}`, `room-types`, and `guests`      |
| Store/update data in local DB              | ✅     | Uses `updateOrCreate()` and `firstOrNew()->save()`                  |
| Rate limiting                              | ✅     | `usleep(500000)` = 2 requests per second                             |
| Progress feedback                          | ✅     | Uses `line()`, `warn()`, `error()` with emoji-based console output   |
| Safe re-run (idempotent)                   | ✅     | Checks if booking has changed before updating                        |
| Log sync progress                          | ✅     | Stored in `sync_logs` via `logSync()`                                |

## ✅ Technical Requirements

| Requirement                  | Status | Notes                                                                 |
|------------------------------|--------|-----------------------------------------------------------------------|
| Laravel HTTP Client          | ✅     | `Http::pms()->get()`                                                 |
| Error Handling and Logging   | ✅     | `try/catch`, Laravel logs, and `sync_logs` tracking                   |
| Transactions                 | ✅     | `DB::beginTransaction()` + `commit()` or `rollBack()`                |
| Laravel conventions          | ✅     | Artisan command structure, PSR-12 compliance                         |
| Unit tests                   | ✅     | Two feature tests provided                                           |

## Summary: Flow Diagram

```text
sync:bookings
     │
     ├── BookingSyncService::syncBookings()
     │   ├── fetch /bookings (with optional ?updated_at.gt=...)
     │   └─ foreach booking_id in chunk (e.g. 100 items):
     │       ├─ if booking exists locally → logSync(type: booking, status: skipped)
     │       ├─ fetch /bookings/{id}
     │       ├─ fetch /rooms/{room_id}
     │       ├─ fetch /room-types/{room_type_id}
     │       ├─ foreach guest_id:
     │       │     └─ fetch /guests/{id}
     │       ├─ validate guest list
     │       │     └─ logSync(type: booking, status: failed, reason: mismatched guest_ids)
     │       ├─ prepare rooms[], room_types[], guests[], bookings[] arrays
     │       └─ upsert all
     └─ logSync(type: logTest, status: info, message: "Sync complete")
```

## 🧪 Testing

### 1. SyncBookingsSafeTest

Verifies booking 1001, guest 401, room 201, room_type 301 exist or are updated.
```bash
php artisan test tests/Feature/SyncBookingsSafeTest.php
```

### 2. SyncResourcesSafeTest

Checks if room/room type/guest exist in DB from faked API responses.
```bash
php artisan test tests/Feature/SyncResourcesSafeTest.php
```

✅ These tests do not delete real DB data.
