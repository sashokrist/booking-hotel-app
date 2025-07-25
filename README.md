# Hotel Booking Sync Application

This is a Laravel-based application designed to synchronize booking data from an external Property Management System (PMS) API into a local database. It provides a console command to fetch and update bookings, guests, rooms, and room types.

## Features

- **Data Synchronization**: Syncs bookings, guests, rooms, and room types from a remote PMS API.
- **Incremental Sync**: Fetches only the data that has been updated since the last sync using an `updated_at.gt` filter.
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
   // in app/Providers/AppServiceProvider.php
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

Run the command manually:

```bash
php artisan sync:bookings
```

Or pass a custom timestamp (ISO 8601 format):

```bash
php artisan sync:bookings --since="2023-10-26T10:00:00Z"
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

- **Laravel log file**: Located at `storage/logs/laravel.log`, for internal debugging and failures.
- **Database sync log**: A `sync_logs` table tracks each attempt for each resource:

### `logSync()` Helper

This method is used to persist sync results:

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

Used for:

- Guests: `success` or `failed`
- Bookings: `success`, `skipped`, or `failed`
- General logs: e.g., type `logTest` for high-level progress info

---

## ✅ Functional Requirements

| Requirement                                | Status | Notes                                                                 |
|--------------------------------------------|--------|-----------------------------------------------------------------------|
| Fetch all bookings from PMS                | ✅     | `GET /api/bookings` implemented with filtering via `updated_at.gt`   |
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
| Unit tests                   | ⚠️     | Not included — can be added optionally (`php artisan make:test`)     |

---

## Summary: Flow Diagram

```text
sync:bookings
     │
     ├── fetch /bookings (updated_at.gt)
     │
     └─ foreach booking:
         ├─ fetch /bookings/{id}
         ├─ fetch /rooms/{id}
         ├─ fetch /room-types/{id}
         ├─ foreach guest_id:
         │     └─ fetch /guests/{id}
         ├─ validate + compare
         ├─ updateOrCreate models
         └─ logSync() per step


```🧪 Testing
1. SyncBookingsSafeTest (Booking test)
This feature test ensures that a fake booking with ID 1001 is processed correctly without deleting or altering any existing data in the real database.

File: tests/Feature/SyncBookingsSafeTest.php

php artisan test tests/Feature/SyncBookingsSafeTest.php
✅ Verifies:

Booking 1001 is created or updated.

Guest 401 is fetched and saved.

Room 201 and RoomType 301 are synced if not already present.

⚠️ Does not roll back DB changes — safe to run only if IDs are real or reserved for testing.

2. SyncResourcesSafeTest (Room + RoomType + Guest test)
This test fakes external API responses to sync a specific guest, room, and room type using IDs that already exist in the production database (e.g., 401, 201, 301), without changing existing data.

File: tests/Feature/SyncResourcesSafeTest.php

php artisan test tests/Feature/SyncResourcesSafeTest.php
✅ Verifies:

Guest with ID 401 exists in DB

Room with ID 201 exists in DB

RoomType with ID 301 exists in DB

✅ Asserts only presence — no destructive behavior or changes required.

🧪 General Test Instructions
To run all tests:

php artisan test
To run a specific test file:

php artisan test tests/Feature/SyncBookingsSafeTest.php
php artisan test tests/Feature/SyncResourcesSafeTest.php
✅ Note: These tests do not use RefreshDatabase, so your data remains intact.

## 📸 Screenshot

![Hotel Booking Sync](public/screenshots/hotel-booking.png)


