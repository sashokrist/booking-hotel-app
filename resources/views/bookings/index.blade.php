@extends('layouts.app')

@section('content')
<div class="container my-4">
    @if(session('message'))
    <div class="alert alert-success">
        {{ session('message') }}
    </div>
@endif
    <div class="row mb-4">
        <div class="col-12">
            <h4 class="fw-bold">API Overview</h4>
            <div class="d-flex flex-wrap gap-3">
                <div class="text-center p-3 rounded shadow-sm" style="background-color: #f8f9fa; color: #000; min-width: 120px; flex: 1;">
                    <h5 class="mb-0">{{ number_format($overview['bookings']) }}</h5>
                    <small>Initial Bookings</small>
                </div>
                <div class="text-center p-3 rounded shadow-sm" style="background-color: #f8f9fa; color: #000; min-width: 120px; flex: 1;">
                    <h5 class="mb-0">{{ number_format($overview['guests']) }}+</h5>
                    <small>Guests</small>
                </div>
                <div class="text-center p-3 rounded shadow-sm" style="background-color: #f8f9fa; color: #000; min-width: 120px; flex: 1;">
                    <h5 class="mb-0">{{ $overview['rooms'] }}</h5>
                    <small>Rooms (max 150)</small>
                </div>
                <div class="text-center p-3 rounded shadow-sm" style="background-color: #f8f9fa; color: #000; min-width: 120px; flex: 1;">
                    <h5 class="mb-0">{{ $overview['roomTypes'] }}</h5>
                    <small>Room Types (max 10)</small>
                </div>
                <div class="text-center p-3 rounded shadow-sm" style="background-color: #f8f9fa; color: #000; min-width: 120px; flex: 1;">
                    <h5 class="mb-0">{{ $overview['rateLimit'] }}</h5>
                    <small>Rate Limit</small>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">📘 Booking Records</h2>
        <div class="d-flex gap-2">
            <button id="viewToggle" class="btn btn-outline-primary btn-sm">🔁 Switch to Table View</button>
        </div>
    </div>
    <form method="POST" action="{{ route('bookings.sync') }}" class="d-flex align-items-center gap-2">
    @csrf
        <input type="date" name="since" class="form-control form-control-sm" value="{{ request('since') }}">
        <button type="submit" class="btn btn-primary btn-sm">🔁 Update bookings</button>
    </form>
    <div id="cardView">
        @foreach($bookings as $booking)
            <div class="card mb-4 shadow-sm">
                <div class="card-body">
                    <p>📘 <strong>Booking ID:</strong> {{ $booking->id }} |
                       <strong>External ID:</strong> {{ $booking->external_id ?? 'N/A' }} |
                       <strong>Room ID:</strong> {{ $booking->room_id }} |
                       <strong>Guest IDs:</strong> {{ implode(', ', $booking->guest_ids ?? []) }}</p>

                    <p>📅 <strong>Check-in:</strong> {{ $booking->check_in }} |
                       <strong>Check-out:</strong> {{ $booking->check_out }} |
                       <strong>Status:</strong> {{ $booking->status }} |
                       <strong>Notes:</strong> {{ $booking->notes }}</p>

                    @if($booking->room)
                    <p>🏨 Room: ID {{ $booking->room->id }} |
                       Number: {{ $booking->room->number }} |
                       RoomType: {{ $booking->room->roomType->name ?? 'N/A' }} |
                       Floor: {{ $booking->room->floor }}</p>
                    @endif

                    @if($booking->room && $booking->room->roomType)
                    <p>🛏️ RoomType: ID {{ $booking->room->roomType->id }} |
                       RoomType Name: {{ $booking->room->roomType->name }} |
                       RoomType Description: {{ $booking->room->roomType->description }}</p>
                    @endif

                    @foreach($booking->guests() as $guest)
                        <p>👤 Guest ID: {{ $guest->id }}, Name: {{ $guest->first_name }} {{ $guest->last_name }}</p>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
    <div id="tableView" class="table-responsive d-none">
        <table class="table table-bordered table-striped">
            <thead class="table-light">
                <tr>
                    <th>Booking ID</th>
                    <th>Room</th>
                    <th>Guests</th>
                    <th>Check-in</th>
                    <th>Check-out</th>
                    <th>Status</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                @foreach($bookings as $booking)
                    <tr>
                        <td>{{ $booking->id }}</td>
                        <td>
                            ID: {{ $booking->room?->id }}<br>
                            Number: {{ $booking->room?->number }}<br>
                            Type: {{ $booking->room?->roomType?->name }}
                        </td>
                        <td>
                            @foreach($booking->guests() as $guest)
                                {{ $guest->first_name }} {{ $guest->last_name }}<br>
                            @endforeach
                        </td>
                        <td>{{ $booking->check_in }}</td>
                        <td>{{ $booking->check_out }}</td>
                        <td>{{ $booking->status }}</td>
                        <td>{{ $booking->notes }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-3">
        {{ $bookings->links('pagination::bootstrap-5') }}
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.getElementById('viewToggle').addEventListener('click', () => {
        const cardView = document.getElementById('cardView');
        const tableView = document.getElementById('tableView');
        const button = document.getElementById('viewToggle');

        const isCard = !cardView.classList.contains('d-none');
        cardView.classList.toggle('d-none', isCard);
        tableView.classList.toggle('d-none', !isCard);
        button.textContent = isCard ? '🔁 Switch to Card View' : '🔁 Switch to Table View';
    });

    document.getElementById('toggleDarkMode').addEventListener('click', () => {
        document.body.classList.toggle('bg-dark');
        document.body.classList.toggle('text-light');

        document.querySelectorAll('.card').forEach(c => c.classList.toggle('bg-dark'));
        document.querySelectorAll('.card').forEach(c => c.classList.toggle('text-light'));
    });
</script>
@endsection
