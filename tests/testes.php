<?php
/**
 * Testes automáticos do plugin — rodam sem WordPress, sem banco e sem internet.
 *
 *   Como rodar:   php tests/testes.php
 *   Resultado:    uma linha ✔ ou ✘ por verificação, e o total no fim.
 *                 Sai com código 1 se algo falhar (útil para automação).
 *
 * ---------------------------------------------------------------------------
 * COMO É POSSÍVEL TESTAR UM PLUGIN SEM WORDPRESS?
 * ---------------------------------------------------------------------------
 * As peças importantes (empacotador, cálculo de preço, leitura do XML) foram
 * escritas como funções "puras": recebem números, devolvem números. As poucas
 * funções do WordPress que a API usa são substituídas aqui por versões falsas
 * ("stubs"), que gravam em memória o que o WordPress gravaria no banco, e
 * devolvem respostas da Total Express que nós controlamos.
 */

// Só roda pela linha de comando. Se a pasta tests/ for parar dentro de
// wp-content/plugins, abrir este arquivo pelo navegador não executa nada.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// ---------------------------------------------------------------------------
// Imitações mínimas do WordPress
// ---------------------------------------------------------------------------
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('CVTE_USUARIO', 'usuario-teste');
define('CVTE_SENHA', 'senha-teste');

$GLOBALS['opcoes'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['chamadas_http'] = 0;
$GLOBALS['resposta_falsa'] = ['code' => 200, 'body' => ''];

function get_option($nome, $padrao = false) { return $GLOBALS['opcoes'][$nome] ?? $padrao; }
function update_option($nome, $valor, $autoload = null) { $GLOBALS['opcoes'][$nome] = $valor; return true; }
function delete_option($nome) { unset($GLOBALS['opcoes'][$nome]); return true; }

// Chaves secretas falsas, como as do wp-config.php.
define('AUTH_KEY', str_repeat('a1B2c3D4', 8));
define('SECURE_AUTH_KEY', str_repeat('Z9y8X7w6', 8));
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t) { $GLOBALS['transients'][$k] = $v; return true; }
function wp_json_encode($v) { return json_encode($v); }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function is_wp_error($x) { return false; }
function wp_remote_post($url, $args) {
    $GLOBALS['chamadas_http']++;
    $GLOBALS['ultimo_envio'] = $args;
    return $GLOBALS['resposta_falsa'];
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }

require __DIR__ . '/../includes/class-cvte-config.php';
require __DIR__ . '/../includes/class-cvte-api.php';
require __DIR__ . '/../includes/class-cvte-empacotador.php';

// ---------------------------------------------------------------------------
// Mini "framework" de teste: uma função que compara e anota o resultado.
// ---------------------------------------------------------------------------
$falhas = 0;
$total = 0;
function verificar(string $descricao, $obtido, $esperado): void
{
    global $falhas, $total;
    $total++;
    $ok = is_float($esperado) ? abs($obtido - $esperado) < 0.005 : $obtido === $esperado;
    if (!$ok) {
        $falhas++;
    }
    printf("  %s %s%s\n", $ok ? '✔' : '✘', $descricao,
        $ok ? '' : '  → obtido: ' . var_export($obtido, true) . ' · esperado: ' . var_export($esperado, true));
}
function secao(string $titulo): void { echo "\n$titulo\n"; }

/** Monta uma resposta SOAP de sucesso no formato do WSDL da Total Express. */
function resposta_ok(string $valor, string $prazo): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
        . '<SOAP-ENV:Body><ns1:calcularFreteResponse xmlns:ns1="urn:calcularFrete"><calcularFreteResponse>'
        . '<CodigoProc>1</CodigoProc><DadosFrete><Prazo>' . $prazo . '</Prazo><ValorServico>' . $valor . '</ValorServico></DadosFrete>'
        . '</calcularFreteResponse></ns1:calcularFreteResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';
}

// Regras de exemplo de uma loja: Simples 6,7%, R$ 3 de embalagem por volume.
$cfg = ['simples_pct' => 6.7, 'embalagem_volume' => 3.0] + CVTE_Config::padroes();

// ---------------------------------------------------------------------------
secao('1. Conversão de valores monetários');
verificar('"22,80" → 22.80', CVTE_API::para_numero('22,80'), 22.80);
verificar('"22.80" → 22.80 (não 2280!)', CVTE_API::para_numero('22.80'), 22.80);
verificar('"1.234,56" → 1234.56', CVTE_API::para_numero('1.234,56'), 1234.56);
verificar('"1,234.56" → 1234.56', CVTE_API::para_numero('1,234.56'), 1234.56);
verificar('"R$ 19,37" → 19.37', CVTE_API::para_numero('R$ 19,37'), 19.37);
verificar('texto vazio → 0', CVTE_API::para_numero(''), 0.0);

// ---------------------------------------------------------------------------
secao('2. Leitura da resposta da Total Express');
$r = CVTE_API::interpretar(resposta_ok('21,37', '4'));
verificar('sucesso é reconhecido', $r['ok'], true);
verificar('valor lido', $r['valor'], 21.37);
verificar('prazo lido', $r['prazo'], 4);

$r = CVTE_API::interpretar('<x><CodigoProc>3</CodigoProc><ErroConsultaFrete>CEP nao atendido</ErroConsultaFrete></x>');
verificar('recusa é reconhecida', $r['ok'], false);
verificar('mensagem de erro da Total Express é preservada', str_contains($r['erro'], 'CEP nao atendido'), true);

$r = CVTE_API::interpretar('<soap:Fault><faultstring>Unauthorized</faultstring></soap:Fault>');
verificar('erro SOAP é reconhecido', str_contains($r['erro'], 'Unauthorized'), true);

$r = CVTE_API::interpretar('<html>Servidor em manutenção</html>');
verificar('resposta estranha não vira preço', $r['ok'], false);

// ---------------------------------------------------------------------------
secao('3. Envelope SOAP enviado');
$xml = CVTE_API::montar_envelope([
    'TipoServico' => 'STD', 'CepDestino' => '01310100', 'Peso' => '10,00', 'ValorDeclarado' => '150,00',
    'TipoEntrega' => 0, 'Altura' => 20, 'Largura' => 30, 'Profundidade' => 40,
]);
$doc = new DOMDocument();
verificar('é um XML válido', @$doc->loadXML($xml), true);
verificar('contém o CEP', str_contains($xml, '>01310100</CepDestino>'), true);
$xml_hostil = CVTE_API::montar_envelope([
    'TipoServico' => '<script>', 'CepDestino' => '1', 'Peso' => '1', 'ValorDeclarado' => '1',
    'TipoEntrega' => 0, 'Altura' => 1, 'Largura' => 1, 'Profundidade' => 1,
]);
verificar('caracteres especiais são escapados', str_contains($xml_hostil, '&lt;script&gt;'), true);

// ---------------------------------------------------------------------------
secao('4. Empacotamento em volumes de até 30 kg');
$saco = ['nome' => 'Saco 25 kg', 'peso' => 25.0, 'dims' => [80, 50, 10], 'valor' => 300.0];
$kg = ['nome' => 'Pacote 1 kg', 'peso' => 1.0, 'dims' => [20, 15, 5], 'valor' => 13.0];

$e = CVTE_Empacotador::empacotar([$saco + ['qtd' => 2]], $cfg);
verificar('2 sacos de 25 kg → 2 volumes', count($e['volumes']), 2);
verificar('cada volume com 25 kg', $e['volumes'][0]['peso'], 25.0);

$e = CVTE_Empacotador::empacotar([$kg + ['qtd' => 30]], $cfg);
verificar('30 × 1 kg → 1 volume', count($e['volumes']), 1);

$e = CVTE_Empacotador::empacotar([$kg + ['qtd' => 31]], $cfg);
verificar('31 × 1 kg → 2 volumes', count($e['volumes']), 2);
verificar('pesos 30 + 1', array_column($e['volumes'], 'peso'), [30.0, 1.0]);

$e = CVTE_Empacotador::empacotar([$saco + ['qtd' => 1], $kg + ['qtd' => 5]], $cfg);
verificar('saco 25 kg + 5 kg → 1 volume de 30 kg', [count($e['volumes']), $e['volumes'][0]['peso']], [1, 30.0]);

$e = CVTE_Empacotador::empacotar([$saco + ['qtd' => 1], $kg + ['qtd' => 6]], $cfg);
verificar('saco 25 kg + 6 kg → 2 volumes', count($e['volumes']), 2);

$e = CVTE_Empacotador::empacotar([['nome' => 'Barril', 'peso' => 35.0, 'dims' => [50, 50, 50], 'valor' => 1, 'qtd' => 1]], $cfg);
verificar('item de 35 kg → indisponível', $e['ok'], false);

$e = CVTE_Empacotador::empacotar([['nome' => 'Fermentador', 'peso' => 5.0, 'dims' => [130, 40, 40], 'valor' => 1, 'qtd' => 1]], $cfg);
verificar('item de 130 cm → indisponível', $e['ok'], false);

$e = CVTE_Empacotador::empacotar([['nome' => 'Levedura', 'peso' => 0.02, 'dims' => [5, 3, 1], 'valor' => 30, 'qtd' => 1]], $cfg);
verificar('item pequeno usa a caixa mínima 20×16×10', $e['volumes'][0]['dims'], [20.0, 16.0, 10.0]);

$e = CVTE_Empacotador::empacotar([$kg + ['qtd' => 3]], $cfg);
verificar('valor declarado soma os itens do volume', $e['volumes'][0]['valor'], 39.0);

// Folga da caixa: só vale para 2 ou mais itens.
$unid = ['dims' => [40.0, 30.0, 20.0], 'cm3' => 24000];
verificar('1 item: medidas do cadastro, sem folga', CVTE_Empacotador::medidas([$unid], 0.15), [40.0, 30.0, 20.0]);
$volume_sem = array_product(CVTE_Empacotador::medidas([$unid, $unid], 0.0));
$volume_com = array_product(CVTE_Empacotador::medidas([$unid, $unid], 0.15));
verificar('2 itens: caixa com folga tem ≥ 15% mais volume', $volume_com >= 1.15 * 48000, true);
verificar('2 itens: caixa com folga é maior que sem folga', $volume_com > $volume_sem, true);

$e = CVTE_Empacotador::empacotar([['nome' => 'Caixa grande', 'peso' => 5.0, 'dims' => [120, 80, 60], 'valor' => 1, 'qtd' => 1]], $cfg);
verificar('item no limite (120×80×60) continua cabendo com folga ligada', $e['volumes'][0]['dims'], [120.0, 80.0, 60.0]);

$e = CVTE_Empacotador::empacotar([$kg + ['qtd' => 30]], $cfg);
verificar('30 × 1 kg continua 1 volume com a folga padrão', count($e['volumes']), 1);

$e = CVTE_Empacotador::empacotar([$kg + ['qtd' => CVTE_Empacotador::MAX_UNIDADES + 1]], $cfg);
verificar('pedido acima do teto de unidades é recusado sem empacotar', [$e['ok'], $e['volumes']], [false, []]);

// ---------------------------------------------------------------------------
secao('5. Preço final ao cliente (exemplo: custo de R$ 21,37)');
$sem_embalagem = ['embalagem_volume' => 0] + $cfg;
verificar('dividir: 21,37 ÷ 0,933 = 22,90', CVTE_Config::preco_final([21.37], $sem_embalagem), 22.90);
verificar('multiplicar: 21,37 × 1,067 = 22,80',
    CVTE_Config::preco_final([21.37], ['simples_modo' => 'multiplicar'] + $sem_embalagem), 22.80);
verificar('com embalagem: (21,37 + 3) ÷ 0,933 = 26,12', CVTE_Config::preco_final([21.37], $cfg), 26.12);
verificar('2 volumes: (60 + 60 + 2×3) ÷ 0,933 = 135,05', CVTE_Config::preco_final([60.0, 60.0], $cfg), 135.05);

// O teste que justifica o modo "dividir": depois de pagar o Simples, sobra o custo.
$preco = CVTE_Config::preco_final([21.37], $sem_embalagem);
verificar('após pagar 6,7% de Simples, sobra o custo da Total Express', round($preco * (1 - 0.067), 2), 21.37);

// ---------------------------------------------------------------------------
secao('6. Chamada à API, cache e credenciais');
$GLOBALS['resposta_falsa'] = ['code' => 200, 'body' => resposta_ok('21,37', '4')];
$r1 = CVTE_API::cotar('01310-100', 10, 150, [40, 30, 20], $cfg);
$r2 = CVTE_API::cotar('01310100', 10, 150, [40, 30, 20], $cfg);
verificar('primeira cotação vai à Total Express', $r1['cache'], false);
verificar('segunda cotação idêntica sai do cache', $r2['cache'], true);
verificar('só uma chamada HTTP no total', $GLOBALS['chamadas_http'], 1);
verificar('autenticação HTTP Basic é enviada',
    $GLOBALS['ultimo_envio']['headers']['Authorization'], 'Basic ' . base64_encode('usuario-teste:senha-teste'));
verificar('a senha não vai dentro do XML', str_contains($GLOBALS['ultimo_envio']['body'], 'senha-teste'), false);

$GLOBALS['resposta_falsa'] = ['code' => 401, 'body' => 'Unauthorized'];
$r = CVTE_API::cotar('40000000', 1, 50, [20, 16, 10], $cfg);
verificar('HTTP 401 vira mensagem clara', str_contains($r['erro'], 'Confira usuário e senha'), true);

$r = CVTE_API::cotar('123', 1, 50, [20, 16, 10], $cfg);
verificar('CEP incompleto é recusado sem chamar a API', $r['ok'], false);

// ---------------------------------------------------------------------------
secao('7. Cache de falhas e limpeza do cache');
$recusa = '<x><CodigoProc>3</CodigoProc><ErroConsultaFrete>CEP nao atendido</ErroConsultaFrete></x>';

$GLOBALS['transients'] = [];
$GLOBALS['resposta_falsa'] = ['code' => 200, 'body' => $recusa];
CVTE_API::cotar('41000000', 2, 50, [20, 16, 10], $cfg, false);
verificar('teste do painel (guardar_falhas = false) não guarda a falha', count($GLOBALS['transients']), 0);

CVTE_API::cotar('41000000', 2, 50, [20, 16, 10], $cfg);
verificar('checkout (padrão) guarda a falha por 5 minutos', count($GLOBALS['transients']), 1);

$GLOBALS['transients'] = [];
$GLOBALS['chamadas_http'] = 0;
$GLOBALS['resposta_falsa'] = ['code' => 200, 'body' => resposta_ok('30,00', '3')];
CVTE_API::cotar('41000000', 5, 80, [30, 20, 15], $cfg);
$GLOBALS['opcoes'][CVTE_API::OPCAO_VERSAO_CACHE] = 2; // o que "Limpar cache" faz
$r = CVTE_API::cotar('41000000', 5, 80, [30, 20, 15], $cfg);
verificar('depois de subir a versão, a cotação não sai do cache', [$r['cache'], $GLOBALS['chamadas_http']], [false, 2]);

// ---------------------------------------------------------------------------
secao('8. Credenciais digitadas no painel (senha criptografada)');
$senha = 'S3nh@ <com> %20 espaços';
$cifrada = CVTE_Config::cifrar($senha);
verificar('cifrar e decifrar devolve a senha original', CVTE_Config::decifrar($cifrada), $senha);
verificar('o texto guardado não contém a senha', str_contains(base64_decode($cifrada), $senha), false);
verificar('duas cifragens da mesma senha são diferentes (nonce)', CVTE_Config::cifrar($senha) !== $cifrada, true);

$adulterada = base64_encode(substr(base64_decode($cifrada), 0, -1) . 'x');
verificar('dado adulterado não é aceito', CVTE_Config::decifrar($adulterada), null);
$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$outra_chave = base64_encode($nonce . sodium_crypto_secretbox($senha, $nonce, str_repeat('k', 32)));
verificar('senha cifrada com outra chave (outro site) não é lida', CVTE_Config::decifrar($outra_chave), null);

verificar('salvar credenciais dá certo', CVTE_Config::salvar_credenciais('loja', $senha), '');
$salvas = CVTE_Config::credenciais_salvas();
verificar('credenciais salvas são lidas de volta', [$salvas['usuario'], $salvas['senha'], $salvas['origem']], ['loja', $senha, 'painel']);
verificar('o banco não guarda a senha em texto puro',
    str_contains(json_encode($GLOBALS['opcoes'][CVTE_Config::OPCAO_CREDENCIAIS]), 'S3nh'), false);

CVTE_Config::salvar_credenciais('loja2', '');
$salvas = CVTE_Config::credenciais_salvas();
verificar('senha em branco mantém a senha anterior', [$salvas['usuario'], $salvas['senha']], ['loja2', $senha]);

$GLOBALS['opcoes'][CVTE_Config::OPCAO_CREDENCIAIS]['senha'] = $outra_chave;
verificar('senha ilegível é sinalizada, sem quebrar', CVTE_Config::credenciais_salvas()['origem'], 'ilegivel');

verificar('constantes do wp-config têm prioridade', CVTE_Config::credenciais()['origem'], 'wp-config');

CVTE_Config::apagar_credenciais();
verificar('apagar remove as credenciais salvas', CVTE_Config::credenciais_salvas()['origem'], 'nenhuma');

// ---------------------------------------------------------------------------
printf("\n%s %d de %d verificações passaram.\n", $falhas ? '✘' : '✔', $total - $falhas, $total);
exit($falhas ? 1 : 0);
