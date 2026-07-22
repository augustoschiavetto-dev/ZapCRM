<?php
// api/sicnet.php
require_once __DIR__ . '/../config/config.php';

/**
 * Autentica o usuário na tabela TABUSER do Sicnet
 */
function sicnet_login($username, $password) {
    $conn = get_db_connection();
    
    if ($conn === false) {
        return [
            'success' => false,
            'message' => 'Não foi possível conectar ao banco de dados SQL Server.'
        ];
    }
    
    // Tratamento com RTRIM e LOWER para garantir compatibilidade com colunas do tipo CHAR/VARCHAR do Sicnet
    $sql = "SELECT nome, senha FROM TABUSER WHERE LOWER(RTRIM(nome)) = LOWER(?) AND inativo = 0";
    $params = [trim($username)];
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        $errorMsg = isset($errors[0]['message']) ? $errors[0]['message'] : 'Erro na consulta.';
        sqlsrv_close($conn);
        return [
            'success' => false,
            'message' => 'Erro na consulta: ' . $errorMsg
        ];
    }
    
    $userFound = false;
    $authenticatedName = '';
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $dbPassword = trim($row['senha']);
        if ($dbPassword === trim($password)) {
            $userFound = true;
            $authenticatedName = trim($row['nome']);
            break;
        }
    }
    
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    
    if ($userFound) {
        return [
            'success' => true,
            'nome' => $authenticatedName
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Usuário ou senha inválidos.'
        ];
    }
}

/**
 * Busca produtos na tabela TABEST1
 */
function sicnet_buscar_produtos($termo, $tipo_busca) {
    $conn = get_db_connection();
    $resultados = [];
    
    if ($conn === false) return $resultados;
    
    if ($tipo_busca === 'codigo') {
        $sql = "SELECT codigo, produto, precovenda, DATALENGTH(foto) as foto_tamanho FROM TABEST1 WHERE codigo = ? OR codigo LIKE ?";
        $params = [$termo, "$termo%"];
    } else {
        $sql = "SELECT codigo, produto, precovenda, DATALENGTH(foto) as foto_tamanho FROM TABEST1 WHERE produto LIKE ?";
        $params = ["%$termo%"];
    }
    
    $sql .= " ORDER BY produto";
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        sqlsrv_close($conn);
        return [];
    }
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $resultados[] = [
            'codigo' => $row['codigo'],
            'produto' => $row['produto'],
            'precovenda' => (float)$row['precovenda'],
            'possui_foto' => ($row['foto_tamanho'] !== null && $row['foto_tamanho'] > 0)
        ];
    }
    
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    return $resultados;
}

/**
 * Obtém a foto BLOB
 */
function sicnet_obter_foto_base64($codigo) {
    $conn = get_db_connection();
    if ($conn === false) return null;
    
    $sql = "SELECT foto FROM TABEST1 WHERE codigo = ?";
    $params = [$codigo];
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) { sqlsrv_close($conn); return null; }
    
    $base64_foto = null;
    if (sqlsrv_fetch($stmt)) {
        $imageStream = sqlsrv_get_field($stmt, 0, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY));
        if ($imageStream) {
            $imageData = '';
            while (!feof($imageStream)) {
                $chunk = fread($imageStream, 8192);
                if ($chunk === false) break;
                $imageData .= $chunk;
            }
            if (!empty($imageData)) $base64_foto = base64_encode($imageData);
        }
    }
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    return $base64_foto;
}
?>