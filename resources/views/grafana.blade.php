<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@300..700&display=swap" rel="stylesheet">
    <style>
        .quicksand-400 {
            font-family: "Quicksand", sans-serif;
            font-optical-sizing: auto;
            font-weight: 400;
            font-style: normal;
        }

        body{
            margin: 0;
            padding: 0;
        }

        .titlediv {
            width: 100%;
            height: 190px;
            text-align: center;
            padding-top: 50px;
        }

        .title {
            font-size: 30px;
            padding-top: 50px;
        }

        .subtitle {
            font-size: 18px;
            font-weight: 100;
            color: rgb(102, 102, 102);
        }

        .dashboard {
            position: relative;
            width: 100%;
            margin: 0 auto;
            background: #fff;
        }

        .panel {
            position: absolute;
            overflow: hidden;
        }

        .panel img {
            width: 100%;
            height: 100%;
            object-fit: fill;
            display: block;
            margin: 4px;
        }

        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body class="quicksand-400">
    @foreach($groupedpanels as $page => $panels)
        @if($page === 1)
            <div class="titlediv">
                <span class="title">{{ $title }}</span>
                <br><br>
                <span class="subtitle">{{ $from }}</span>
                <br>
                <span class="subtitle">to</span>
                <br>
                <span class="subtitle">{{ $to }}</span>
            </div>
        @endif
        <div class="dashboard">
            @foreach($panels as $panel)
                <div class="panel" style="left:{{ $panel['left'] }}px; top:{{ $panel['top'] }}px; width: {{ $panel['width'] }}px; height: {{ $panel['height'] }}px;">
                    <img src="{{ $panel['imageUrl'] }}" alt="Panel Image">
                </div>
            @endforeach
        </div>
        @if (!$loop->last)
            <div class="page-break"></div>
        @endif
    @endforeach
</body>
</html>