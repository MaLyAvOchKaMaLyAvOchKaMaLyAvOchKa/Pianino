<?php
header("Content-Type: text/plain; charset=utf-8");

$filename = isset($_GET['file']) ? $_GET['file'] : 'song.mid';

if (!file_exists($filename)) {
    echo "ERROR: File not found";
    exit;
}

$data = file_get_contents($filename);
$offset = 0;

function readBytes(&$data, &$offset, $length) {
    $bytes = substr($data, $offset, $length);
    $offset += $length;
    return $bytes;
}

function readInt(&$data, &$offset, $length) {
    $bytes = readBytes($data, $offset, $length);
    $val = 0;
    for ($i = 0; $i < $length; $i++) {
        $val = ($val << 8) + ord($bytes[$i]);
    }
    return $val;
}

function readVlv(&$data, &$offset) {
    $val = 0;
    while (true) {
        $byte = ord($data[$offset++]);
        $val = ($val << 7) + ($byte & 0x7F);
        if (($byte & 0x80) == 0) break;
    }
    return $val;
}

$chunkType = readBytes($data, $offset, 4);
if ($chunkType !== "MThd") {
    echo "ERROR: Invalid MIDI file";
    exit;
}

$headerLength = readInt($data, $offset, 4);
$format = readInt($data, $offset, 2);
$tracksCount = readInt($data, $offset, 2);
$division = readInt($data, $offset, 2);

$ticksPerQuarter = ($division & 0x8000) ? 120 : $division;

$rawEvents = [];

for ($t = 0; $t < $tracksCount; $t++) {
    $chunkType = readBytes($data, $offset, 4);
    $chunkLength = readInt($data, $offset, 4);
    $trackEnd = $offset + $chunkLength;
    
    $currentTick = 0;
    $runningStatus = 0;
    
    while ($offset < $trackEnd) {
        $deltaTime = readVlv($data, $offset);
        $currentTick += $deltaTime;
        
        $statusByte = ord($data[$offset]);
        if ($statusByte & 0x80) {
            $runningStatus = $statusByte;
            $offset++;
        } else {
            $statusByte = $runningStatus;
        }
        
        $eventType = $statusByte & 0xF0;
        $channel = $statusByte & 0x0F;
        
        if ($eventType == 0x90) {
            $note = ord($data[$offset++]);
            $velocity = ord($data[$offset++]);
            
            $ms = round(($currentTick * 500000) / ($ticksPerQuarter * 1000));
            
            if ($velocity > 0) {
                $rawEvents[] = ['time' => $ms, 'note' => $note, 'vel' => $velocity, 'type' => 'on', 'channel' => $channel, 'track' => $t];
            } else {
                $rawEvents[] = ['time' => $ms, 'note' => $note, 'vel' => 0, 'type' => 'off', 'channel' => $channel, 'track' => $t];
            }
            
        } elseif ($eventType == 0x80) {
            $note = ord($data[$offset++]);
            $velocity = ord($data[$offset++]); // считываем второй байт (velocity/release)
            
            $ms = round(($currentTick * 500000) / ($ticksPerQuarter * 1000));
            $rawEvents[] = ['time' => $ms, 'note' => $note, 'vel' => 0, 'type' => 'off', 'channel' => $channel, 'track' => $t];
            
        } elseif ($eventType == 0xC0 || $eventType == 0xD0) {
            $offset += 1;
        } elseif ($eventType == 0xE0 || $eventType == 0xA0 || $eventType == 0xB0) {
            $offset += 2;
        } elseif ($statusByte == 0xFF) {
            $metaType = ord($data[$offset++]);
            $len = readVlv($data, $offset);
            $offset += $len;
        }
    }
}

// Сортируем события по времени
usort($rawEvents, function($a, $b) {
    return $a['time'] - $b['time'];
});

// Связываем Note On с Note Off для вычисления длительности
$activeNotes = [];
$output = [];

foreach ($rawEvents as $ev) {
    $time = $ev['time'];
    $note = $ev['note'];
    $vel = $ev['vel'];
    $type = $ev['type'];
    $channel = $ev['channel'];
    
    // Ключ для отслеживания конкретной ноты
    $key = $note . '_' . $channel;
    
    if ($type == 'on') {
        // Если нота уже играла, принудительно закрываем ее
        if (isset($activeNotes[$key])) {
            $startData = $activeNotes[$key];
            $duration = max(50, $time - $startData['time']);
            $output[] = "{$startData['time']},{$startData['note']},{$startData['vel']},{$duration},{$startData['channel']}";
        }
        $activeNotes[$key] = ['time' => $time, 'note' => $note, 'vel' => $vel, 'channel' => $channel];
    } else {
        if (isset($activeNotes[$key])) {
            $startData = $activeNotes[$key];
            $duration = max(50, $time - $startData['time']);
            $output[] = "{$startData['time']},{$startData['note']},{$startData['vel']},{$duration},{$startData['channel']}";
            unset($activeNotes[$key]);
        }
    }
}

echo implode(";", $output);
?>
