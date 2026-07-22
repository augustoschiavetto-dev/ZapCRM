<?php
// api/sicnet.php
require_once __DIR__ . '/../config/config.php';

/**
 * Authenticates user against TABUSER table
 * Returns array with status and user details, or error message
 */
function sicnet_login($username, $password) {
    $conn = get_db_connection();
    if ($conn === false) {
        return [
            'success' => false,
            'message' => 'Erro de conexão com o banco de dados SICNET: ' . print_r(sqlsrv_errors(), true)
        ];
    }
    
    // TABUSER has columns 'nome' and 'senha'
    $sql = "SELECT nome FROM TABUSER WHERE nome = ? AND senha = ?";
    $params = [$username, $password];
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        $error = 'Erro na consulta: ' . print_r(sqlsrv_errors(), true);
        sqlsrv_close($conn);
        return [
            'success' => false,
            'message' => $error
        ];
    }
    
    $success = sqlsrv_has_rows($stmt);
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    
    if ($success) {
        return [
            'success' => true,
            'nome' => $username
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Usuário ou senha inválidos.'
        ];
    }
}

/**
 * Searches products in TABEST1.
 * Returns array of matching products.
 */
function sicnet_buscar_produtos($termo, $tipo_busca) {
    $conn = get_db_connection();
    $resultados = [];
    
    if ($conn === false) {
        return $resultados; // Return empty if database is offline
    }
    
    if ($tipo_busca === 'codigo') {
        // Search by exact code or prefix
        $sql = "SELECT codigo, produto, precovenda, DATALENGTH(foto) as foto_tamanho 
                FROM TABEST1 
                WHERE codigo = ? OR codigo LIKE ?";
        $params = [$termo, "$termo%"];
    } elseif ($tipo_busca === 'referencia') {
        // Search by reference. In Sicnet reference is typically in 'referencia' or 'ref' column
        // We will query dynamically. If the column 'referencia' doesn't exist, we fall back to description.
        // First check columns of TABEST1 to avoid database errors
        $sql_cols = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'TABEST1' AND COLUMN_NAME = 'referencia'";
        $stmt_cols = sqlsrv_query($conn, $sql_cols);
        $has_ref = false;
        if ($stmt_cols !== false) {
            if (sqlsrv_has_rows($stmt_cols)) {
                $has_ref = true;
            }
            sqlsrv_free_stmt($stmt_cols);
        }
        
        if ($has_ref) {
            $sql = "SELECT codigo, produto, precovenda, DATALENGTH(foto) as foto_tamanho 
                    FROM TABEST1 
                    WHERE referencia LIKE ?";
            $params = ["%$termo%"];
        } else {
            // Fallback to description if referencia doesn't exist
            $sql = "SELECT codigo, produto, precovenda, DATALENGTH(foto) as foto_tamanho 
                    FROM TABEST1 
                    WHERE produto LIKE ?";
            $params = ["%$termo%"];
        }
    } else {
        // Search by description (produto)
        $sql = "SELECT codigo, produto, precovenda, DATALENGTH(foto) as foto_tamanho 
                FROM TABEST1 
                WHERE produto LIKE ?";
        $params = ["%$termo%"];
    }
    
    // Order the results by product name
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
 * Retrieves the BLOB product photo from SQL Server and encodes it to Base64
 * Returns base64 string or null if not found
 */
function sicnet_obter_foto_base64($codigo) {
    $conn = get_db_connection();
    if ($conn === false) {
        return null;
    }
    
    $sql = "SELECT foto FROM TABEST1 WHERE codigo = ?";
    $params = [$codigo];
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        sqlsrv_close($conn);
        return null;
    }
    
    $base64_foto = null;
    
    if (sqlsrv_fetch($stmt)) {
        // Read BLOB field as binary stream
        $imageStream = sqlsrv_get_field($stmt, 0, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY));
        if ($imageStream) {
            $imageData = '';
            while (!feof($imageStream)) {
                $chunk = fread($imageStream, 8192);
                if ($chunk === false) {
                    break;
                }
                $imageData .= $chunk;
            }
            
            if (!empty($imageData)) {
                $base64_foto = base64_encode($imageData);
                
                // Also write a copy to the local temp directory for caching or debugging purposes
                $tempFilename = 'prod_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $codigo) . '.jpg';
                $tempPath = DIR_MEDIA . DIRECTORY_SEPARATOR . $tempFilename;
                @file_put_contents($tempPath, $imageData);
            }
        }
    }
    
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    return $base64_foto;
}
?>
