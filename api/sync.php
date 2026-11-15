<?php
require_once __DIR__ . '/config.php';

function site_db(): PDO {
    $pdo = new PDO('sqlite:' . __DIR__ . '/../healthtime.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec("CREATE TABLE IF NOT EXISTS pareceres (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        paciente_nome TEXT NOT NULL,
        medico_nome TEXT NOT NULL,
        texto TEXT NOT NULL,
        estado_evento TEXT DEFAULT 'SEM ALTERAÇÃO',
        data_hora DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $cols = $pdo->query('PRAGMA table_info(pareceres)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('estado_evento', $cols, true)) $pdo->exec("ALTER TABLE pareceres ADD COLUMN estado_evento TEXT DEFAULT 'SEM ALTERAÇÃO'");
    if (!in_array('origem_id', $cols, true)) $pdo->exec('ALTER TABLE pareceres ADD COLUMN origem_id TEXT');
    if (!in_array('sincronizado_app', $cols, true)) $pdo->exec('ALTER TABLE pareceres ADD COLUMN sincronizado_app INTEGER NOT NULL DEFAULT 0');
    // Dá um identificador estável aos pareceres antigos para também poder sincronizá-los.
    $pdo->exec("UPDATE pareceres SET origem_id = 'healthtime-site-parecer-' || id WHERE origem_id IS NULL OR origem_id = ''");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_pareceres_origem_id ON pareceres(origem_id)');
    return $pdo;
}

function enviar_pareceres_pendentes(PDO $pdo): array {
    if (SYNC_TOKEN === '' || APP_URL === '' || str_contains(APP_URL, 'SEU-APP-HOSPEDADO')) {
        return ['enviados'=>0, 'pendentes'=>null, 'configurado'=>false];
    }
    $stmt = $pdo->query("SELECT id, origem_id, paciente_nome, medico_nome, texto, estado_evento, data_hora
        FROM pareceres WHERE COALESCE(sincronizado_app, 0) = 0 AND origem_id IS NOT NULL ORDER BY id ASC LIMIT 100");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $enviados = 0;
    $marcar = $pdo->prepare('UPDATE pareceres SET sincronizado_app = 1 WHERE id = ?');
    foreach ($rows as $row) {
        $payload = [
            'origem_id'=>$row['origem_id'], 'paciente_nome'=>$row['paciente_nome'],
            'medico_nome'=>$row['medico_nome'], 'texto'=>$row['texto'],
            'estado_evento'=>$row['estado_evento'] ?: 'SEM ALTERAÇÃO', 'data_hora'=>$row['data_hora']
        ];
        $ctx = stream_context_create(['http'=>[
            'method'=>'POST', 'header'=>"Content-Type: application/json\r\nAuthorization: Bearer ".SYNC_TOKEN."\r\n",
            'content'=>json_encode($payload, JSON_UNESCAPED_UNICODE), 'timeout'=>12, 'ignore_errors'=>true
        ]]);
        $body = @file_get_contents(APP_URL.'/api/receber_observacao.php', false, $ctx);
        $resp = $body === false ? null : json_decode($body, true);
        if (!is_array($resp) || ($resp['status'] ?? '') !== 'sucesso') break;
        $marcar->execute([(int)$row['id']]);
        $enviados++;
    }
    return ['enviados'=>$enviados, 'pendentes'=>count($rows)-$enviados, 'configurado'=>true];
}
