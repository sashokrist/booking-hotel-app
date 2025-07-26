<?php

namespace App\Traits;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Booking;

trait BookingSyncHelpers
{
    protected int $delay = 500000; // Default delay (used by service)

    private function fetchUpdatedBookingIds(?string $since): array
    {
        try {
            $response = Http::pms()->get('bookings', ['updated_at.gt' => $since]);
            usleep($this->delay);

            if ($response->failed()) {
                Log::error("❌ Failed to fetch booking IDs", ['response' => $response->body()]);
                return [];
            }

            return collect($response->json('data') ?? [])
                ->map(fn ($item) => is_array($item) ? ($item['id'] ?? null) : (is_numeric($item) ? $item : null))
                ->filter(fn ($id) => is_numeric($id))
                ->unique()
                ->values()
                ->all();
        } catch (\Exception $e) {
            Log::error("❌ Exception while fetching booking IDs", ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function bookingExists(int $bookingId): bool
    {
        return Booking::where('id', $bookingId)->exists();
    }

    private function fetchBooking(int $bookingId): ?array
    {
        return Cache::remember("booking:{$bookingId}", 3600, function () use ($bookingId) {
            $response = Http::pms()->get("bookings/{$bookingId}");
            usleep($this->delay);
            return $response->successful() ? $response->json() : null;
        });
    }

    private function fetchRoom(array $booking): ?array
    {
        return Cache::remember("room:{$booking['room_id']}", 3600, function () use ($booking) {
            $response = Http::pms()->get("rooms/{$booking['room_id']}");
            usleep($this->delay);
            return $response->successful() ? $response->json() : null;
        });
    }

    private function fetchRoomType(int $roomTypeId): ?array
    {
        return Cache::remember("room_type:{$roomTypeId}", 3600, function () use ($roomTypeId) {
            $response = Http::pms()->get("room-types/{$roomTypeId}");
            usleep($this->delay);
            return $response->successful() ? $response->json() : null;
        });
    }

    private function fetchGuests(array $guestIds, Command $console): array
    {
        $guestsToSync = [];
        $guestNames = [];
        $syncedGuestIds = [];

        foreach ($guestIds as $guestId) {
            $guest = Cache::remember("guest:{$guestId}", 3600, function () use ($guestId) {
                $response = Http::pms()->get("guests/{$guestId}");
                usleep($this->delay);
                return $response->successful() ? $response->json() : null;
            });

            if (empty($guest['id'])) {
                continue;
            }

            $guestsToSync[$guest['id']] = [
                'id' => $guest['id'],
                'first_name' => $guest['first_name'] ?? null,
                'last_name' => $guest['last_name'] ?? null,
                'email' => $guest['email'] ?? null,
                'phone' => $guest['phone'] ?? null,
            ];

            $syncedGuestIds[] = (int) $guest['id'];
            $guestName = trim(($guest['first_name'] ?? '') . ' ' . ($guest['last_name'] ?? ''));
            $guestNames[] = $guestName;

            $console->line("Guest ID: {$guest['id']}, Name: {$guestName}");
        }

        return compact('guestsToSync', 'guestNames', 'syncedGuestIds');
    }
}
