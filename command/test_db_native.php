<?php
try {
    $pdo = new PDO('mysql:host=51.222.154.116;dbname=aifreelas_payment_db', 'aifreelas_prod', 'wadg8gopxolw4lta');
    echo "Conexão funcionou perfeitamente!";
} catch (PDOException $e) {
    echo "Erro de conexão: " . $e->getMessage();
}
