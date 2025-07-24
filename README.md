# Hotel Booking Sync Application

This is a Laravel-based application designed to synchronize booking data from an external Property Management System (PMS) API into a local database. It provides a console command to fetch and update bookings, guests, rooms, and room types.

## Features

- **Data Synchronization**: Syncs bookings, guests, rooms, and room types from a remote PMS API.
- **Incremental Sync**: Fetches only the data that has been updated since the last sync using an `updated_at.gt` filter.
- **Robust Error Handling**: Includes transaction-based database operations to ensure data integrity and detailed logging for debugging.
- **API Rate Limiting**: Implements a simple delay between API requests to avoid hitting rate limits.
- **Configurable**: Easily configure the PMS API endpoint and credentials.

## Requirements

- PHP >= 8.1
- Composer
- Laravel >= 10.x
- A supported database (e.g., MySQL, PostgreSQL)

## Installation

1.  **Clone the repository:**
    ```bash
    git clone <repository-url>
    cd hotel-booking
    ```

2.  **Install dependencies:**
    ```bash
    composer install
    ```

3.  **Create your environment file:**
    ```bash
    cp .env.example .env
    ```

4.  **Generate an application key:**
    ```bash
    php artisan key:generate
    ```

5.  **Configure your database:**
    Update the `DB_*` variables in your `.env` file with your database credentials.

6.  **Run database migrations:**
    This will create the necessary tables: `bookings`, `guests`, `rooms`, `room_types`, and `sync_logs`.
    ```bash
    php artisan migrate
    ```

7.  **Configure the PMS API:**
    The API connection is configured in `app/Providers/AppServiceProvider.php` using an `Http` macro. You may need to update the `base_uri` and add authentication headers if required by the PMS API. The SSL certificate verification uses `cacert.pem` at the project root.

    ```php
    // in app/Providers/AppServiceProvider.php
    Http::macro('pms', function () {
        return Http::withOptions([
            'verify' => base_path('cacert.pem'), // Ensure this file exists
            'base_uri' => 'https://api.pms.donatix.info/api/',
            // Add 'headers' => ['Authorization' => 'Bearer YOUR_API_TOKEN'] if needed
        ]);
    });
    ```

## Usage

The primary way to use this application is through the `sync:bookings` Artisan command.

### Manual Sync

To run a sync, execute the following command in your terminal:

```bash
php artisan sync:bookings
```

By default, this will fetch all bookings updated in the last 24 hours.

You can also specify a "since" date to sync bookings updated after a specific timestamp (ISO 8601 format):

```bash
php artisan sync:bookings --since="2023-10-26T10:00:00Z"
```

### Scheduled Sync

For automated, regular synchronization, you can schedule this command to run periodically. Add the following to the `schedule` method in `app/Console/Kernel.php`:

```php
// in app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    // Example: Run the sync every 15 minutes
    $schedule->command('sync:bookings')->everyFifteenMinutes();
}
```

Make sure your server's cron is configured to run Laravel's scheduler.

## Logging

The application logs important events and errors to the default Laravel log file located at `storage/logs/laravel.log`.
- Successful sync operations for each booking are logged with `INFO` level.
- Errors during the sync process are logged with `ERROR` level, including the booking ID and error message.
- A global failure to connect to the API is also logged.