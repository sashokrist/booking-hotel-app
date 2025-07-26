<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hotel Booking App</title>

    <style>
        body {
            margin: 0;
            background-color: #000; /* black background */
            color: white;
            font-family: Figtree, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }

        h1 {
            font-size: 20px;
            margin-bottom: 100px;
        }

        .button-container {
            border: 4px solid black;
            background: white;
            color: black;
            padding: 40px 80px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: all 0.2s ease-in-out;
        }

        .button-container:hover {
            background: #f0f0f0;
        }
    </style>
</head>
<body>

    <h1>HOTEL BOOKING APP</h1>

    <a href="http://127.0.0.1:8000/bookings" class="button-container">GO TO BOOKING</a>

</body>
</html>
