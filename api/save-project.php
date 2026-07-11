<?php
// Simple flat-file backend for the portfolio admin page.
// No database needed — reads/writes data/portfolio-extra.json on this server.
header('Content-Type: application/json');

$dataFile = __DIR__ . '/../data/portfolio-extra.json';

function loadData($dataFile) {
    if (!file_exists($dataFile)) {
        return ['pinHash' => null, 'projects' => []];
    }
    $raw = file_get_contents($dataFile);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['pinHash' => null, 'projects' => []];
    }
    if (!array_key_exists('pinHash', $data)) $data['pinHash'] = null;
    if (!isset($data['projects']) || !is_array($data['projects'])) $data['projects'] = [];
    return $data;
}

function saveData($dataFile, $data) {
    $dir = dirname($dataFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $fp = fopen($dataFile, 'c+');
    if (!$fp) return false;
    $ok = false;
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        $ok = true;
    }
    fclose($fp);
    return $ok;
}

function pinMatches($data, $pin) {
    if (empty($data['pinHash'])) return false;
    return hash_equals($data['pinHash'], hash('sha256', (string) $pin));
}

function fail($code, $message) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    fail(400, 'Invalid request.');
}

$action = $input['action'] ?? '';
$data = loadData($dataFile);

switch ($action) {

    case 'status':
        echo json_encode(['ok' => true, 'hasPin' => !empty($data['pinHash'])]);
        break;

    case 'setPin':
        if (!empty($data['pinHash'])) {
            fail(403, 'A PIN is already set.');
        }
        $newPin = (string) ($input['newPin'] ?? '');
        if (strlen($newPin) < 4) {
            fail(400, 'PIN must be at least 4 characters.');
        }
        $data['pinHash'] = hash('sha256', $newPin);
        if (!saveData($dataFile, $data)) fail(500, 'Could not save. Check that the data folder is writable.');
        echo json_encode(['ok' => true, 'projects' => $data['projects']]);
        break;

    case 'verify':
        $pin = (string) ($input['pin'] ?? '');
        if (!pinMatches($data, $pin)) {
            fail(401, 'Incorrect PIN.');
        }
        echo json_encode(['ok' => true, 'projects' => $data['projects']]);
        break;

    case 'changePin':
        $pin = (string) ($input['pin'] ?? '');
        $newPin = (string) ($input['newPin'] ?? '');
        if (!pinMatches($data, $pin)) {
            fail(401, 'Incorrect current PIN.');
        }
        if (strlen($newPin) < 4) {
            fail(400, 'New PIN must be at least 4 characters.');
        }
        $data['pinHash'] = hash('sha256', $newPin);
        if (!saveData($dataFile, $data)) fail(500, 'Could not save. Check that the data folder is writable.');
        echo json_encode(['ok' => true]);
        break;

    case 'add':
        $pin = (string) ($input['pin'] ?? '');
        if (!pinMatches($data, $pin)) {
            fail(401, 'Incorrect PIN.');
        }
        $project = $input['project'] ?? null;
        if (!is_array($project)) {
            fail(400, 'Missing project data.');
        }
        foreach (['section', 'type', 'title', 'category', 'desc', 'src'] as $key) {
            if (empty($project[$key])) {
                fail(400, "Missing field: $key");
            }
        }
        $clean = [
            'id' => uniqid('p_', true),
            'section' => (string) $project['section'],
            'type' => (string) $project['type'],
            'title' => (string) $project['title'],
            'category' => (string) $project['category'],
            'desc' => (string) $project['desc'],
            'src' => (string) $project['src'],
        ];
        if (!empty($project['poster'])) $clean['poster'] = (string) $project['poster'];

        $data['projects'][] = $clean;
        if (!saveData($dataFile, $data)) fail(500, 'Could not save. Check that the data folder is writable.');
        echo json_encode(['ok' => true, 'projects' => $data['projects']]);
        break;

    case 'delete':
        $pin = (string) ($input['pin'] ?? '');
        if (!pinMatches($data, $pin)) {
            fail(401, 'Incorrect PIN.');
        }
        $id = (string) ($input['id'] ?? '');
        $data['projects'] = array_values(array_filter($data['projects'], function ($p) use ($id) {
            return ($p['id'] ?? '') !== $id;
        }));
        if (!saveData($dataFile, $data)) fail(500, 'Could not save. Check that the data folder is writable.');
        echo json_encode(['ok' => true, 'projects' => $data['projects']]);
        break;

    default:
        fail(400, 'Unknown action.');
}
