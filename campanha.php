<?php
/*
============================================================================
== GERADOR DE CHAMADAS AUTOMÁTICAS PARA ASTERISK / INCREDIBLEPBX          ==
==                                                                        ==
== Autor: Ivann Sampaio                                                   ==
== Versão: 1.4                                                            ==
== Descrição: Permite criar campanhas de discagem a partir de CSV ou      ==
==            entrada manual, com seleção de áudio e tronco de saída.     ==
==            Carrega as configurações do arquivo config.php.             ==
==            Adicionada integração com o sistema de autenticação do      ==
==            FreePBX/IncrediblePBX para proteger o acesso.               ==
============================================================================
 */

// --- INÍCIO DA INTEGRAÇÃO DE SEGURANÇA DO FREEPBX ---
// Este bloco de código carrega o ambiente do FreePBX e verifica se o
// usuário está autenticado. Se não estiver, ele redireciona para a página de login.
if (!@include_once(getenv('FREEPBX_CONF') ? getenv('FREEPBX_CONF') : '/etc/freepbx.conf')) {
    include_once('/etc/asterisk/freepbx.conf');
}
// O objeto $amp_user é criado pelo bootstrap e contém os dados do usuário logado.
// Se não existir ou o usuário não tiver permissão, o acesso é bloqueado.
if (!isset($amp_user) || !$amp_user->checkSection('campanhas')) {
    // Você pode criar uma "Feature Code" chamada 'campanhas' no FreePBX
    // para dar permissão granular, ou simplesmente verificar se o usuário está logado.
    // Por simplicidade aqui, vamos apenas checar se o objeto existe.
    // A linha abaixo é a mais importante:
    if (!isset($amp_user)) {
        header('Location: /admin/config.php'); // Redireciona para a página de login
        exit;
    }
}
// --- FIM DA INTEGRAÇÃO DE SEGURANÇA ---

// --- LÓGICA DO SCRIPT ---

// Carrega o arquivo de configuração. O script irá parar se o arquivo não for encontrado.
if (file_exists('config.php')) {
    require_once 'config.php';
} else {
    // Exibe uma mensagem de erro amigável se o arquivo de configuração não for encontrado.
    die("<h1>Erro Crítico</h1><p>O arquivo de configuração <code>config.php</code> não foi encontrado. Por favor, crie o arquivo e configure-o corretamente antes de usar a ferramenta.</p>");
}


// Lógica para fornecer o arquivo CSV modelo para download
if (isset($_GET['download_csv']) && $_GET['download_csv'] == 'true') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="modelo_contatos.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Nome', 'Telefone']);
    fputcsv($output, ['Joao Silva', '11999998888']);
    fputcsv($output, ['Maria Souza', '21988887777']);
    fputcsv($output, ['Lead Exemplo', '3135556677']);
    exit();
}


// Função auxiliar para buscar os arquivos de áudio
function getAudioFiles($dir) {
    $audio_files = [];
    if (!is_dir($dir)) return $audio_files;
    $files = scandir($dir);
    foreach ($files as $file) {
        if (is_file($dir . $file) && preg_match('/\\.(wav|gsm|ulaw|alaw|sln16)$/i', $file)) {
            $audio_files[] = pathinfo($file, PATHINFO_FILENAME);
        }
    }
    sort($audio_files);
    return $audio_files;
}

$audio_options = getAudioFiles($sounds_dir);
$messages = [];
$errors = [];

// Variável para guardar o Caller ID para exibição no formulário (evita XSS e mantém o valor após o envio)
$display_caller_id = $default_caller_id;

// Processa o formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contacts = [];
    
    // Pega o Caller ID cru para o arquivo .call.
    $caller_id_for_file = !empty($_POST['caller_id']) ? $_POST['caller_id'] : $default_caller_id;
    // Atualiza a variável de exibição com o valor que foi enviado
    $display_caller_id = $caller_id_for_file;

    $audio_file = isset($_POST['audio_file']) ? basename(htmlspecialchars($_POST['audio_file'])) : null;
    $selected_trunk = isset($_POST['trunk_context']) ? $_POST['trunk_context'] : null;

    if (empty($audio_file) || !in_array($audio_file, $audio_options)) {
        $errors[] = "Erro: Arquivo de áudio inválido ou não selecionado.";
    }
    if (empty($selected_trunk) || !array_key_exists($selected_trunk, $available_trunks)) {
        $errors[] = "Erro: Tronco de saída inválido ou não selecionado.";
    }

    // 1. Processar o upload do arquivo CSV
    if (isset($_FILES['csvfile']) && $_FILES['csvfile']['error'] === UPLOAD_ERR_OK) {
        $csv_path = $_FILES['csvfile']['tmp_name'];
        if (($handle = fopen($csv_path, "r")) !== FALSE) {
            fgetcsv($handle); // Pula o cabeçalho
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (isset($data[0]) && isset($data[1])) {
                    $name = trim($data[0]);
                    $phone = preg_replace('/[^0-9]/', '', trim($data[1]));
                    if (!empty($name) && !empty($phone)) {
                        $contacts[] = ['name' => $name, 'phone' => $phone];
                    }
                }
            }
            fclose($handle);
            $messages[] = "Arquivo CSV processado com sucesso.";
        } else {
            $errors[] = "Não foi possível abrir o arquivo CSV enviado.";
        }
    }
    // 2. Processar a entrada manual
    elseif (!empty($_POST['manual_name']) && !empty($_POST['manual_phone'])) {
        $name = trim(htmlspecialchars($_POST['manual_name']));
        $phone = preg_replace('/[^0-9]/', '', trim($_POST['manual_phone']));
        if (!empty($name) && !empty($phone)) {
            $contacts[] = ['name' => $name, 'phone' => $phone];
            $messages[] = "Contato manual adicionado à fila de discagem.";
        } else {
            $errors[] = "Nome ou telefone fornecido manualmente é inválido.";
        }
    }

    // 3. Gerar os arquivos .call
    if (empty($errors) && !empty($contacts)) {
        if (!is_writable($spool_dir)) {
            $errors[] = "ERRO CRÍTICO DE PERMISSÃO: O diretório de spool '{$spool_dir}' não tem permissão de escrita pelo servidor web.";
        } else {
            $calls_generated = 0;
            foreach ($contacts as $contact) {
                $phone_number = $contact['phone'];
                $contact_name = $contact['name'];
                
                $call_content  = "Channel: {$selected_trunk}/{$phone_number}\n";
                $call_content .= "CallerID: {$caller_id_for_file}\n";
                $call_content .= "MaxRetries: 1\n";
                $call_content .= "RetryTime: 60\n";
                $call_content .= "WaitTime: 45\n";
                $call_content .= "Context: {$call_context}\n";
                $call_content .= "Extension: s\n";
                $call_content .= "Priority: 1\n";
                $call_content .= "Application: Playback\n";
                $call_content .= "Data: custom/{$audio_file}\n";

                $call_filename = "campanha_{$phone_number}_" . time() . "_" . uniqid() . ".call";
                $temp_filepath = "/tmp/{$call_filename}";
                $final_filepath = $spool_dir . $call_filename;

                if (file_put_contents($temp_filepath, $call_content)) {
                    if (rename($temp_filepath, $final_filepath)) {
                        $messages[] = "Chamada para {$contact_name} ({$phone_number}) agendada.";
                        $calls_generated++;
                    } else {
                        $errors[] = "Falha ao mover o arquivo .call para o diretório de spool para o número {$phone_number}.";
                    }
                } else {
                    $errors[] = "Falha ao escrever o arquivo .call temporário para o número {$phone_number}.";
                }
            }
            if ($calls_generated > 0) {
                $messages[] = "<strong>Total de {$calls_generated} chamadas agendadas com sucesso.</strong>";
            }
        }
    } elseif (empty($errors)) {
        $errors[] = "Nenhum dado para processar. Por favor, envie um arquivo CSV ou preencha os campos manuais.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerador de Campanhas de Chamada - Asterisk</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; background-color: #f0f2f5; color: #1c1e21; }
        .container { max-width: 850px; margin: 2em auto; background: #fff; padding: 2em; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        h1, h2 { color: #1877f2; border-bottom: 2px solid #e7f3ff; padding-bottom: 10px; }
        h2 { font-size: 1.2em; text-align: center; color: #606770; border: none; margin-top: 2em; }
        form { margin-top: 1.5em; }
        fieldset { border: 1px solid #dddfe2; padding: 1.5em; margin-bottom: 1.5em; border-radius: 6px; }
        legend { font-weight: bold; color: #1877f2; padding: 0 10px; font-size: 1.1em; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #4b4f56; }
        input[type="text"], input[type="file"], select { width: 100%; padding: 12px; margin-bottom: 1em; border: 1px solid #ccd0d5; border-radius: 6px; box-sizing: border-box; font-size: 16px; }
        input[type="file"] { padding: 8px; }
        input[type="submit"] { background-color: #1877f2; color: white; padding: 12px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 18px; font-weight: bold; width: 100%; transition: background-color 0.3s; }
        input[type="submit"]:hover { background-color: #166fe5; }
        .message { padding: 1em; margin-bottom: 1em; border-radius: 6px; border: 1px solid transparent; }
        .success { background-color: #e7f3ff; border-color: #bde0fe; color: #0c5460; }
        .error { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .info { padding: 1em; background-color: #fffbe6; border-left: 5px solid #ffc107; margin-top: 2em; }
        small { color: #606770; }
        .download-link { display: inline-block; margin-top: 5px; font-size: 0.9em; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Gerador de Campanhas de Chamada</h1>

        <?php
        // Exibe as mensagens de sucesso e erro
        if (!empty($messages)) {
            echo '<div class="message success">' . implode('<br>', $messages) . '</div>';
        }
        if (!empty($errors)) {
            echo '<div class="message error">' . implode('<br>', $errors) . '</div>';
        }
        ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data">
            
            <fieldset>
                <legend>Origem dos Contatos</legend>
                <p>Você pode enviar um arquivo CSV <strong>ou</strong> digitar um único contato manualmente.</p>
                
                <label for="csvfile">Opção A: Importar via Arquivo CSV</label>
                <input type="file" id="csvfile" name="csvfile" accept=".csv,text/csv">
                <small>O CSV deve ter duas colunas: <strong>Nome</strong> e <strong>Telefone</strong>. A primeira linha (cabeçalho) será ignorada.</small>
                <a href="?download_csv=true" class="download-link">Baixar modelo de CSV</a>
                
                <h2>OU</h2>

                <label for="manual_name">Opção B: Digitar Manualmente</label>
                <input type="text" id="manual_name" name="manual_name" placeholder="Nome do Contato">
                <input type="text" id="manual_phone" name="manual_phone" placeholder="Telefone (ex: 11987654321)">
            </fieldset>

            <fieldset>
                <legend>Configurações da Chamada</legend>

                <label for="trunk_context">Tronco de Saída:</label>
                <select id="trunk_context" name="trunk_context" required>
                    <option value="">-- Selecione um Tronco --</option>
                     <?php foreach ($available_trunks as $trunk_key => $trunk_name): ?>
                        <option value="<?php echo htmlspecialchars($trunk_key); ?>"><?php echo htmlspecialchars($trunk_name); ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Os troncos são configurados no arquivo <code>config.php</code>.</small>
                <br><br>

                <label for="audio_file">Áudio para Tocar:</label>
                <select id="audio_file" name="audio_file" required>
                    <option value="">-- Selecione um Áudio --</option>
                    <?php if (empty($audio_options)): ?>
                        <option value="" disabled>Nenhum áudio encontrado em <?php echo htmlspecialchars($sounds_dir); ?></option>
                    <?php else: ?>
                        <?php foreach ($audio_options as $audio): ?>
                            <option value="<?php echo htmlspecialchars($audio); ?>"><?php echo htmlspecialchars($audio); ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <small>Os áudios listados foram encontrados em <code><?php echo htmlspecialchars($sounds_dir); ?></code></small>

                <br><br>

                <label for="caller_id">Número de Origem (Caller ID):</label>
                <input type="text" id="caller_id" name="caller_id" value="<?php echo htmlspecialchars($display_caller_id); ?>">
                <small>Formato recomendado: "Nome" &lt;numero&gt;. <strong>Atenção às regras da ANATEL (0303) para telemarketing.</strong></small>
            </fieldset>

            <input type="submit" value="Iniciar Campanha">
        </form>

        <div class="info">
            <h3>Como Funciona e Avisos Importantes</h3>
            <p>Esta página cria arquivos de chamada (<code>.call</code>) e os coloca no diretório <code><?php echo htmlspecialchars($spool_dir); ?></code>. O Asterisk automaticamente processa esses arquivos para iniciar as chamadas. Assim que a pessoa atende, o áudio selecionado é tocado.</p>
            <p><strong>Uso Responsável:</strong> O uso de sistemas de discagem automática para telemarketing é estritamente regulamentado. No Brasil, a LGPD e as regras da ANATEL (como o prefixo 0303 para chamadas de oferta de produtos e serviços) devem ser seguidas. O uso indevido desta ferramenta é de sua inteira responsabilidade e pode resultar em penalidades severas.</p>
        </div>
    </div>
</body>
</html>

