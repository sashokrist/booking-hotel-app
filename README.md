
# Hotel Booking Sync Application

This is a Laravel-based application designed to synchronize booking data from an external Property Management System (PMS) API into a local database. It provides a console command to fetch and update bookings, guests, rooms, and room types.

## Features

- **Data Synchronization**: Syncs bookings, guests, rooms, and room types from a remote PMS API.
- **Full and Incremental Sync**: Can fetch all bookings or only updated ones using an optional `--since` filter (`updated_at.gt`).
- **Robust Error Handling**: Includes transaction-based database operations to ensure data integrity and detailed logging for debugging.
- **API Rate Limiting**: Implements a simple delay between API requests to avoid hitting rate limits.
- **Logging Sync Results**: Saves sync metadata for each resource in a `sync_logs` table for auditing and review.
- **Configurable**: Easily configure the PMS API endpoint and credentials.

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

---

## ✅ Functional Requirements

| Requirement                                | Status | Notes                                                                 |
|--------------------------------------------|--------|-----------------------------------------------------------------------|
| Fetch all bookings from PMS                | ✅     | Supports full sync and filtered sync via `updated_at.gt`              |
| Fetch related room, room type, and guest   | ✅     | Implemented using `GET /rooms/{id}`, `room-types`, and `guests`      |
| Store/update data in local DB              | ✅     | Using `updateOrCreate()` and `firstOrNew()->save()`                  |
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
ssync:bookings
     │
     ├── fetch /bookings (with optional ?updated_at.gt=...)
     │
     └─ foreach booking_id in chunk (e.g. 100 items):
         ├─ if booking exists locally
         │     └─ logSync(type: booking, status: skipped)
         ├─ fetch /bookings/{id}
         ├─ fetch /rooms/{room_id}
         ├─ fetch /room-types/{room_type_id}
         ├─ foreach guest_id:
         │     └─ fetch /guests/{id}
         ├─ validate guest list
         │     └─ logSync(type: booking, status: failed, reason: mismatched guest_ids)
         ├─ prepare rooms[], room_types[], guests[], bookings[] arrays
         └─ end foreach

     ├─ upsert rooms[]
     ├─ upsert room_types[]
     ├─ upsert guests[]
     ├─ upsert bookings[]
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

## 📸 Screenshot

![Hotel Booking Sync](docs/hotel-booking.png)
