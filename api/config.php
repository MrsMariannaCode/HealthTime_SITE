<?php
// Configure estas variáveis no ambiente do servidor. O token deve ser igual ao do APP.
define('APP_URL', rtrim((string) (getenv('HEALTHTIME_APP_URL') ?: 'https://shiny-fiesta-x55p5r6qq6qvh9vjx-8000.app.github.dev/'), '/'));
define('SYNC_TOKEN', (string) (getenv('HEALTHTIME_SYNC_TOKEN') ?: ''));