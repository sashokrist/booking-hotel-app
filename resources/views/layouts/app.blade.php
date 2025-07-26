<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotel Booking Sync</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Optional Custom Styles -->
    <style>
        body.dark-mode {
            background-color: #121212;
            color: #f1f1f1;
        }
        .dark-mode .card {
            background-color: #1e1e1e;
            border-color: #333;
        }
        .dark-mode .navbar {
            background-color: #000 !important;
        }
        .dark-mode .page-link {
            background-color: #222;
            color: #ddd;
        }
    </style>
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark px-3">
        <a class="navbar-brand" href="{{ route('bookings.index') }}">📘 Hotel Sync</a>
        <div class="ms-auto">
            <button class="btn btn-outline-light btn-sm" id="darkModeToggle">🌙 Toggle Dark Mode</button>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="container mt-4">
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @yield('content')
    </main>

    <!-- Bootstrap + Dark Mode JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const toggleBtn = document.getElementById('darkModeToggle');
        const body = document.body;

        function setDarkMode(enabled) {
            if (enabled) {
                body.classList.add('dark-mode');
                localStorage.setItem('darkMode', 'true');
            } else {
                body.classList.remove('dark-mode');
                localStorage.setItem('darkMode', 'false');
            }
        }

        toggleBtn.addEventListener('click', () => {
            const isDark = body.classList.contains('dark-mode');
            setDarkMode(!isDark);
        });

        // Apply saved mode on load
        window.addEventListener('DOMContentLoaded', () => {
            const saved = localStorage.getItem('darkMode') === 'true';
            setDarkMode(saved);
        });
    </script>

<script>
    document.getElementById('viewToggle').addEventListener('click', () => {
        const cardView = document.getElementById('cardView');
        const tableView = document.getElementById('tableView');
        const button = document.getElementById('viewToggle');

        const isCard = !cardView.classList.contains('d-none');
        if (isCard) {
            cardView.classList.add('d-none');
            tableView.classList.remove('d-none');
            button.textContent = '🔁 Switch to Card View';
        } else {
            cardView.classList.remove('d-none');
            tableView.classList.add('d-none');
            button.textContent = '🔁 Switch to Table View';
        }
    });
</script>

</body>
</html>
