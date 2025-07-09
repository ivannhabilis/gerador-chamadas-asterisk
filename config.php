<?php
/*
============================================================================
== ARQUIVO DE CONFIGURAÇÃO PARA O GERADOR DE CHAMADAS                     ==
==                                                                        ==
== Instruções:                                                            ==
== 1. Edite as variáveis abaixo com os dados do seu servidor Asterisk.    ==
== 2. Salve este arquivo como 'config.php' no mesmo diretório do          ==
==    script principal 'campanha.php'.                                    ==
============================================================================
*/

// --- CONFIGURAÇÕES DO ASTERISK ---

/**
 * @var string Diretório de spool do Asterisk. Onde os arquivos .call são colocados.
 */
$spool_dir = '/var/spool/asterisk/outgoing/';

/**
 * @var string Diretório onde seus áudios de campanha personalizados estão armazenados.
 * Verifique o caminho do idioma (pt_BR, en, etc.) no seu sistema.
 */
$sounds_dir = '/var/lib/asterisk/sounds/pt_BR/custom/';

/**
 * @var array Troncos disponíveis para seleção na interface.
 * 'Chave' => 'Valor' onde a chave é o que o Asterisk usa (ex: PJSIP/tronco)
 * e o valor é o nome amigável que aparecerá na lista.
 */
$available_trunks = [
    'PJSIP/gvsip' => 'Tronco Google Voice (gvsip)',
    'PJSIP/SeuOutroTronco' => 'Tronco Principal Voip',
    'PJSIP/1002' => 'Ramal 1002',
    // Adicione mais troncos aqui conforme necessário
];

/**
 * @var string O contexto do dialplan para onde a chamada é enviada após ser atendida.
 * 'from-internal' é o padrão seguro na maioria dos sistemas.
 */
$call_context = 'from-internal';

/**
 * @var string O número de telefone (Caller ID) que será exibido como padrão.
 * Lembre-se da regulamentação da ANATEL (prefixo 0303) para telemarketing.
 */
$default_caller_id = '"Sua Empresa" <03030000000>';

?>
