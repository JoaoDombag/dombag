<?php
declare(strict_types=1);

// ══════════════════════════════════════════════════════════════════════════════
//  DOMBAG — leads_ia_lib.php
//  Biblioteca compartilhada: prospecção de leads com IA (OpenAI + MySQL)
//  Inclua APÓS config.php e auth.php.
// ══════════════════════════════════════════════════════════════════════════════

if (!defined('OPENAI_API_URL')) {
    define('OPENAI_API_URL',    'https://api.openai.com/v1/responses');
    define('OPENAI_MODEL',      'gpt-5.4-mini');
    define('OPENAI_TOKEN_FIXO', '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');
    define('OPENAI_TIMEOUT',    150);
    define('MAX_RESULTADOS',    25);
}

if (!defined('UF_LIST')) {
    define('UF_LIST', [
        'AC' => 'Acre',            'AL' => 'Alagoas',           'AP' => 'Amapá',
        'AM' => 'Amazonas',        'BA' => 'Bahia',             'CE' => 'Ceará',
        'DF' => 'Distrito Federal','ES' => 'Espírito Santo',    'GO' => 'Goiás',
        'MA' => 'Maranhão',        'MT' => 'Mato Grosso',       'MS' => 'Mato Grosso do Sul',
        'MG' => 'Minas Gerais',    'PA' => 'Pará',              'PB' => 'Paraíba',
        'PR' => 'Paraná',          'PE' => 'Pernambuco',        'PI' => 'Piauí',
        'RJ' => 'Rio de Janeiro',  'RN' => 'Rio Grande do Norte','RS' => 'Rio Grande do Sul',
        'RO' => 'Rondônia',        'RR' => 'Roraima',           'SC' => 'Santa Catarina',
        'SP' => 'São Paulo',       'SE' => 'Sergipe',           'TO' => 'Tocantins',
    ]);
}

if (!defined('LEADS_PRESETS')) {
    define('LEADS_PRESETS', [
        'nutricao_animal' => [
            'titulo'              => 'Nutrição animal / pet food',
            'descricao'           => 'Rações, premix, suplementos e ingredientes para alimentação animal — grande consumidor de sacaria de ráfia e big bag.',
            'segmento'            => 'nutrição animal',
            'subsegmentos'        => 'pet food, fábrica de ração animal, premix, suplementos animais, ingredientes para ração',
            'produto_foco'        => 'sacaria de ráfia e big bag',
            'porte'               => 'médio e grande',
            'cidades_raw'         => '',
            'termos_positivos_raw'=> 'indústria, fabricante, fábrica de ração, nutrição animal, pet food, premix, suplementos animais, ingredientes, embalagem ráfia, saco ráfia, big bag',
            'termos_negativos_raw'=> 'pet shop, veterinária, loja, varejo, marketplace, curso, blog',
            'quantidade'          => '12',
            'somente_com_site'    => true,
            'somente_com_contato' => true,
            'ocultar_clientes'    => true,
            'tipos_empresa'       => ['industria', 'fabricante', 'distribuidora', 'agroindustria'],
            'ufs'                 => ['SP', 'MG', 'PR', 'GO', 'MS'],
        ],
        'fertilizantes' => [
            'titulo'              => 'Fertilizantes / insumos agrícolas',
            'descricao'           => 'Misturadoras, adubos, química agrícola e biossoluções — usam sacaria de ráfia e big bag para granéis e pós.',
            'segmento'            => 'fertilizantes e insumos agrícolas',
            'subsegmentos'        => 'misturadora de fertilizantes, adubos, química agrícola, biossoluções, condicionadores de solo',
            'produto_foco'        => 'sacaria de ráfia e big bag',
            'porte'               => 'médio e grande',
            'cidades_raw'         => '',
            'termos_positivos_raw'=> 'fertilizantes, insumos agrícolas, adubos, mistura, química agrícola, biossoluções, granulado, mineral, saco ráfia, big bag, embalagem granel',
            'termos_negativos_raw'=> 'jardinagem, varejo, loja, revenda pequena, marketplace, curso, blog',
            'quantidade'          => '12',
            'somente_com_site'    => true,
            'somente_com_contato' => true,
            'ocultar_clientes'    => true,
            'tipos_empresa'       => ['industria', 'fabricante', 'distribuidora', 'agroindustria'],
            'ufs'                 => ['SP', 'MG', 'GO', 'MT', 'MS'],
        ],
        'usinas_bioenergia' => [
            'titulo'              => 'Usinas / bioenergia',
            'descricao'           => 'Usinas de açúcar e álcool, etanol, biomassa — usam sacaria de ráfia e big bag para açúcar, torta e subprodutos.',
            'segmento'            => 'usinas e bioenergia',
            'subsegmentos'        => 'usina de açúcar e álcool, etanol, destilaria, bioenergia, sucroalcooleira',
            'produto_foco'        => 'sacaria de ráfia e big bag',
            'porte'               => 'grande',
            'cidades_raw'         => '',
            'termos_positivos_raw'=> 'usina, açúcar, álcool, etanol, bioenergia, biomassa, sucroenergético, destilaria, saco ráfia, big bag, embalagem açúcar',
            'termos_negativos_raw'=> 'revenda, varejo, loja, curso, blog, consumidor final',
            'quantidade'          => '10',
            'somente_com_site'    => true,
            'somente_com_contato' => true,
            'ocultar_clientes'    => true,
            'tipos_empresa'       => ['industria', 'agroindustria', 'cooperativa'],
            'ufs'                 => ['SP', 'GO', 'MG', 'MS'],
        ],
        'sementes_cerealistas' => [
            'titulo'              => 'Sementes / cerealistas / armazenagem',
            'descricao'           => 'Beneficiadoras de sementes, cerealistas e armazéns — principal mercado de sacaria de ráfia e big bag para grãos.',
            'segmento'            => 'sementes, cerealistas e armazenagem',
            'subsegmentos'        => 'beneficiamento de sementes, cerealista, silos, armazéns, beneficiamento de amendoim, cooperativa agrícola',
            'produto_foco'        => 'sacaria de ráfia e big bag',
            'porte'               => 'médio e grande',
            'cidades_raw'         => '',
            'termos_positivos_raw'=> 'sementes, cerealista, armazenagem, grãos, amendoim, beneficiamento, silos, armazéns, saco ráfia, big bag, embalagem grãos',
            'termos_negativos_raw'=> 'loja agropecuária, varejo, consumidor final, curso, blog, marketplace',
            'quantidade'          => '12',
            'somente_com_site'    => true,
            'somente_com_contato' => true,
            'ocultar_clientes'    => true,
            'tipos_empresa'       => ['industria', 'beneficiadora', 'cooperativa', 'agroindustria', 'distribuidora'],
            'ufs'                 => ['SP', 'PR', 'MT', 'GO', 'MS'],
        ],
        'mineracao' => [
            'titulo'              => 'Mineração / minerais industriais',
            'descricao'           => 'Mineradoras, moagem de minerais e calcário — grande demanda de sacaria de ráfia e big bag para granéis pesados.',
            'segmento'            => 'mineração e minerais industriais',
            'subsegmentos'        => 'mineradora, moagem de minerais, calcário, carvão vegetal, minérios industriais',
            'produto_foco'        => 'sacaria de ráfia e big bag',
            'porte'               => 'médio e grande',
            'cidades_raw'         => '',
            'termos_positivos_raw'=> 'mineração, mineral, calcário, minério, carvão, moagem, minerais industriais, saco ráfia, big bag, embalagem granel, embalagem bulk',
            'termos_negativos_raw'=> 'pedreira residencial, varejo, loja, blog, curso, marketplace',
            'quantidade'          => '10',
            'somente_com_site'    => true,
            'somente_com_contato' => true,
            'ocultar_clientes'    => true,
            'tipos_empresa'       => ['mineradora', 'industria', 'beneficiadora'],
            'ufs'                 => ['MG', 'GO', 'SP', 'BA', 'PA'],
        ],
    ]);
}

// ── Helpers básicos ───────────────────────────────────────────────────────────

function iaH(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function iaNormText(?string $v): string
{
    $v = mb_strtolower(trim((string) $v), 'UTF-8');
    $v = strtr($v, [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
    ]);
    $v = preg_replace('/\b(ltda|eireli|me|epp|s\.?a\.?|industria|industrial|comercio|comercial|embalagens|distribuidora|logistica)\b/u', ' ', $v);
    $v = preg_replace('/[^a-z0-9]+/u', ' ', $v);
    return trim((string) preg_replace('/\s+/u', ' ', $v));
}

function iaListFromText(string $raw): array
{
    $parts = preg_split('/[\r\n,;|]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string) $p);
        if ($p !== '') $out[] = $p;
    }
    return array_values(array_unique($out));
}

function iaApiKey(): string
{
    if (defined('OPENAI_API_KEY') && trim((string) constant('OPENAI_API_KEY')) !== '') {
        return trim((string) constant('OPENAI_API_KEY'));
    }
    return trim(OPENAI_TOKEN_FIXO);
}

function iaPresetToForm(array $preset): array
{
    return array_merge($preset, [
        'cidades'          => iaListFromText($preset['cidades_raw'] ?? ''),
        'termos_positivos' => iaListFromText($preset['termos_positivos_raw'] ?? ''),
        'termos_negativos' => iaListFromText($preset['termos_negativos_raw'] ?? ''),
    ]);
}

function iaScoreClass(int $score): string
{
    return $score >= 70 ? 'score-high' : ($score >= 40 ? 'score-mid' : 'score-low');
}

function iaStatusClass(string $status): string
{
    return match(true) {
        $status === 'novo lead'          => 'status-new',
        $status === 'possível duplicado' => 'status-dup',
        $status === 'já é cliente'       => 'status-old',
        $status === 'sem dados'          => 'status-none',
        default                          => 'status-err',
    };
}

// ── OpenAI ────────────────────────────────────────────────────────────────────

function iaBuildSystemPrompt(array $f, int $qtd): string
{
    $ufsTxt        = !empty($f['ufs']) ? implode(', ', $f['ufs']) : 'qualquer UF do Brasil';
    $cidadesTxt    = !empty($f['cidades']) ? implode(', ', $f['cidades']) : 'cidades não restringidas';
    $positivasTxt  = !empty($f['termos_positivos']) ? implode(', ', $f['termos_positivos']) : 'sem termos positivos adicionais';
    $negativasTxt  = !empty($f['termos_negativos']) ? implode(', ', $f['termos_negativos']) : 'sem termos negativos';
    $exigirSite    = $f['somente_com_site'] ? 'SIM' : 'NÃO';
    $exigirContato = $f['somente_com_contato'] ? 'SIM' : 'NÃO';
    $tiposEmpresa  = !empty($f['tipos_empresa']) ? implode(', ', $f['tipos_empresa']) : 'fabricantes, indústrias, distribuidoras, beneficiadoras e empresas B2B';

    return <<<TXT
Você é um assistente de prospecção B2B da DomBag, fabricante brasileira de Big Bags e sacaria de ráfia.
Pesquise empresas reais na web usando a ferramenta de busca.

Objetivo:
Encontrar empresas com bom potencial de compra de embalagens industriais da DomBag.

Contexto desta busca:
- Segmento principal: {$f['segmento']}
- Subsegmentos/observações: {$f['subsegmentos']}
- Produto foco: {$f['produto_foco']}
- Porte desejado: {$f['porte']}
- Estados prioritários: {$ufsTxt}
- Cidades prioritárias: {$cidadesTxt}
- Tipos de empresa desejados: {$tiposEmpresa}
- Termos positivos: {$positivasTxt}
- Termos negativos: {$negativasTxt}
- Exigir site: {$exigirSite}
- Exigir telefone ou e-mail: {$exigirContato}

Regras:
- Máximo de {$qtd} leads.
- Priorize empresas com sinais claros de operação industrial/B2B.
- Exclua empresas que aparentem ser varejo puro, pet shop, consumidor final, marketplace, curso, blog, notícia ou diretório genérico.
- Se houver termos negativos, evite empresas que caiam nessas palavras.
- Se exigir site = SIM, retorne apenas empresas com site verificável.
- Se exigir telefone ou e-mail = SIM, retorne apenas empresas com pelo menos um desses contatos.
- O campo score deve ser inteiro de 0 a 100 e refletir aderência comercial real.
- O campo motivo_relevancia deve ser curto, objetivo e comercial.
- Retorne também o CNPJ da empresa, quando encontrado em fonte pública confiável.
- Se não encontrar com segurança, retorne string vazia em "cnpj".
- Nunca invente CNPJ.
- Quero apenas empresas ativas, não inclua falidas, inativas, em recuperação judicial ou similares.
- Procure por empresas que mostrem sinais de atividade recente, como notícias, publicações, vagas ou atualizações no site.
- Se possível, dê preferência a empresas que mencionem ou mostrem interesse em embalagens, logística, transporte ou temas relacionados ao nosso produto foco.
- Busque também empresas novas e em crescimento, que possam estar iniciando operações ou expandindo, mesmo que ainda não tenham grande presença online.
- Traga apenas empresas com score acima de 60, a menos que haja um motivo muito forte para incluir empresas com score mais baixo (nesse caso, explique claramente no campo motivo_relevancia).
- Para cada empresa, procure o contato responsável pelo setor de compras ou comercial: nome da pessoa e forma de contato (e-mail, telefone, LinkedIn ou WhatsApp).
  Fontes a consultar: site da empresa, LinkedIn, portais de fornecedores, Receita Federal, diretórios B2B.
  Campos: "contato_compras_nome" (nome e cargo, ex: "João Silva – Gerente de Compras") e "contato_compras_meio" (ex: "joao@empresa.com.br" ou "LinkedIn: linkedin.com/in/joaosilva").
- OBRIGATÓRIO: retorne APENAS empresas para as quais conseguiu identificar o nome e a forma de contato do setor de compras ou comercial. Empresas sem esse dado devem ser DESCARTADAS.

Retorne SOMENTE JSON válido, sem markdown e sem texto fora do JSON.
Formato exato:
{
  "leads": [
    {
      "nome_empresa": "",
      "cnpj": "",
      "site": "",
      "telefone": "",
      "email": "",
      "cidade": "",
      "uf": "",
      "segmento": "",
      "fonte": "",
      "motivo_relevancia": "",
      "score": 0,
      "contato_compras_nome": "",
      "contato_compras_meio": ""
    }
  ]
}
TXT;
}

function iaBuildUserPrompt(array $f, int $qtd): string
{
    $ufsTxt       = !empty($f['ufs']) ? implode(', ', $f['ufs']) : '';
    $cidadesTxt   = !empty($f['cidades']) ? implode(', ', $f['cidades']) : '';
    $positivasTxt = !empty($f['termos_positivos']) ? implode(', ', $f['termos_positivos']) : '';
    $negativasTxt = !empty($f['termos_negativos']) ? implode(', ', $f['termos_negativos']) : '';
    $tiposTxt     = !empty($f['tipos_empresa']) ? implode(', ', $f['tipos_empresa']) : '';

    return "Pesquise até {$qtd} empresas com este perfil:\n"
        . "Segmento: {$f['segmento']}\n"
        . "Subsegmentos: {$f['subsegmentos']}\n"
        . "Produto foco: {$f['produto_foco']}\n"
        . "Porte: {$f['porte']}\n"
        . "Estados: {$ufsTxt}\n"
        . "Cidades: {$cidadesTxt}\n"
        . "Tipos de empresa: {$tiposTxt}\n"
        . "Termos positivos: {$positivasTxt}\n"
        . "Termos negativos: {$negativasTxt}";
}

function iaExtrairTexto(array $json): string
{
    if (!empty($json['output_text']) && is_string($json['output_text'])) {
        return $json['output_text'];
    }
    $textos = [];
    foreach (($json['output'] ?? []) as $item) {
        foreach (($item['content'] ?? []) as $c) {
            if (($c['type'] ?? '') === 'output_text' && !empty($c['text'])) {
                $textos[] = $c['text'];
            }
        }
    }
    return trim(implode("\n", $textos));
}

function iaParsear(string $texto): array
{
    $texto = trim($texto);
    if ($texto === '') return [];

    $decoded = json_decode($texto, true);
    if (is_array($decoded)) {
        if (isset($decoded['leads']) && is_array($decoded['leads'])) return $decoded['leads'];
        if (array_is_list($decoded)) return $decoded;
    }

    if (preg_match('/```json\s*(.*?)```/is', $texto, $m)) {
        $d = json_decode(trim($m[1]), true);
        if (is_array($d)) return $d['leads'] ?? (array_is_list($d) ? $d : []);
    }

    if (preg_match('/(\{.*\}|\[.*\])/s', $texto, $m)) {
        $d = json_decode($m[1], true);
        if (is_array($d)) return $d['leads'] ?? (array_is_list($d) ? $d : []);
    }

    return [];
}

function iaChamarOpenAI(array $f): array
{
    $apiKey = iaApiKey();
    if ($apiKey === '' || $apiKey === 'COLE_SUA_OPENAI_API_KEY_AQUI') {
        return ['ok' => false, 'message' => 'Defina OPENAI_TOKEN_FIXO ou OPENAI_API_KEY no config.'];
    }

    $qtd    = max(1, min(MAX_RESULTADOS, (int) $f['quantidade']));
    $system = iaBuildSystemPrompt($f, $qtd);
    $user   = iaBuildUserPrompt($f, $qtd);

    $payload = [
        'model' => OPENAI_MODEL,
        'input' => [
            ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $system]]],
            ['role' => 'user',   'content' => [['type' => 'input_text', 'text' => $user]]],
        ],
        'tools' => [['type' => 'web_search_preview']],
        'text'  => ['format' => ['type' => 'text']],
    ];

    $ch = curl_init(OPENAI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT    => OPENAI_TIMEOUT,
    ]);

    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($errno) return ['ok' => false, 'message' => 'Erro cURL: ' . $err];

    $json = json_decode((string) $raw, true);
    if (!is_array($json)) return ['ok' => false, 'message' => 'Resposta inválida da API.'];
    if ($http >= 400) return ['ok' => false, 'message' => $json['error']['message'] ?? 'Erro HTTP ' . $http];

    $texto = iaExtrairTexto($json);
    $leads = iaParsear($texto);

    return ['ok' => true, 'model_text' => $texto, 'leads' => $leads];
}

// ── ERP Yzidro ────────────────────────────────────────────────────────────────

function iaCarregarClientesYzidro(): array
{
    $pg = dbPG();
    if (!$pg) return [];

    $sql = "
        SELECT DISTINCT
            COALESCE(NULLIF(TRIM(VV.NOME_FANTASIA),''), TRIM(VV.CLIENTE)) AS nome_fantasia,
            TRIM(VV.CLIENTE) AS razao_social
        FROM VW_VENDAS VV
        WHERE VV.COD_GRUPO IN (6)
          AND VV.OPERACAO_PEDIDO = 'V'
          AND VV.EMP_CODIGO = 1
          AND COALESCE(VV.CLIENTE,'') <> ''
        ORDER BY 1
    ";

    $res = @pg_query($pg, $sql);
    if (!$res) return [];

    $clientes = [];
    while ($row = pg_fetch_assoc($res)) {
        $nf = iaNormText($row['nome_fantasia'] ?? '');
        $rs = iaNormText($row['razao_social'] ?? '');
        if ($nf !== '') $clientes[] = ['norm' => $nf, 'original' => $row['nome_fantasia'] ?? ''];
        if ($rs !== '' && $rs !== $nf) $clientes[] = ['norm' => $rs, 'original' => $row['razao_social'] ?? ''];
    }
    pg_free_result($res);
    pg_close($pg);

    return $clientes;
}

function iaComparar(array $lead, array $clientesYzidro): array
{
    $nomeNorm = iaNormText($lead['nome_empresa'] ?? '');
    if ($nomeNorm === '') {
        return ['status' => 'sem dados', 'cliente' => '', 'motivo' => 'nome da empresa em branco'];
    }

    foreach ($clientesYzidro as $cli) {
        $cn = $cli['norm'];
        if ($cn === $nomeNorm) {
            return ['status' => 'já é cliente', 'cliente' => $cli['original'], 'motivo' => 'nome exato'];
        }
        if (str_contains($nomeNorm, $cn) || str_contains($cn, $nomeNorm)) {
            return ['status' => 'já é cliente', 'cliente' => $cli['original'], 'motivo' => 'nome contido'];
        }
        $tol = max(2, (int) floor(min(mb_strlen($nomeNorm), mb_strlen($cn)) * 0.15));
        if (levenshtein($nomeNorm, $cn) <= $tol) {
            return ['status' => 'possível duplicado', 'cliente' => $cli['original'], 'motivo' => 'nome parecido'];
        }
    }

    return ['status' => 'novo lead', 'cliente' => '', 'motivo' => 'não encontrado na carteira'];
}

// ── Persistência MySQL (leads_prospectados) ───────────────────────────────────

function iaProspEnsureSchema(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS LEADS_PROSPECTADOS (
            id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cnpj              VARCHAR(18)      NOT NULL DEFAULT '',
            nome_empresa      VARCHAR(255)     NOT NULL,
            site              VARCHAR(255)     NULL,
            telefone          VARCHAR(60)      NULL,
            email             VARCHAR(180)     NULL,
            cidade            VARCHAR(120)     NULL,
            uf                CHAR(2)          NULL,
            segmento          VARCHAR(180)     NULL,
            fonte             VARCHAR(255)     NULL,
            motivo_relevancia TEXT             NULL,
            score             TINYINT UNSIGNED NOT NULL DEFAULT 0,
            segmento_busca    VARCHAR(255)     NULL,
            dedupe_key        CHAR(64)         NOT NULL,
            status_prosp      VARCHAR(40)      NOT NULL DEFAULT 'NOVO',
            usuario_id        INT UNSIGNED     NULL DEFAULT NULL,
            gravado_em        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_prosp_dedupe (dedupe_key),
            INDEX idx_prosp_uf       (uf),
            INDEX idx_prosp_score    (score),
            INDEX idx_prosp_status   (status_prosp),
            INDEX idx_prosp_segmento (segmento_busca(80)),
            INDEX idx_prosp_usuario  (usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL);

    // Migrações: adiciona colunas em tabelas criadas antes desses campos existirem
    $col = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );

    $col->execute(['LEADS_PROSPECTADOS', 'CNPJ']);
    if (!(int) $col->fetchColumn()) {
        $pdo->exec("ALTER TABLE LEADS_PROSPECTADOS ADD COLUMN cnpj VARCHAR(18) NOT NULL DEFAULT '' AFTER id");
    }

    $col->execute(['LEADS_PROSPECTADOS', 'USUARIO_ID']);
    if (!(int) $col->fetchColumn()) {
        $pdo->exec("ALTER TABLE LEADS_PROSPECTADOS ADD COLUMN usuario_id INT UNSIGNED NULL DEFAULT NULL AFTER status_prosp");
    }

    $col->execute(['LEADS_PROSPECTADOS', 'contato_compras_nome']);
    if (!(int) $col->fetchColumn()) {
        $pdo->exec("ALTER TABLE LEADS_PROSPECTADOS ADD COLUMN contato_compras_nome VARCHAR(255) NULL DEFAULT NULL");
    }

    $col->execute(['LEADS_PROSPECTADOS', 'contato_compras_meio']);
    if (!(int) $col->fetchColumn()) {
        $pdo->exec("ALTER TABLE LEADS_PROSPECTADOS ADD COLUMN contato_compras_meio VARCHAR(255) NULL DEFAULT NULL");
    }
}

/**
 * Retorna lista de usuários do sistema para exibição em dropdowns.
 * Se o parâmetro crm_gru_leads estiver configurado, filtra pelo grupo.
 * @return array<int, array{codigo: int, nome: string}>
 */
function iaGetUsuarios(PDO $pdo): array
{
    try {
        $st = $pdo->prepare('SELECT PAR_VALOR FROM PARAMETROS WHERE PAR_CHAVE = ?');
        $st->execute(['crm_gru_leads']);
        $gruLeads = (int) ($st->fetchColumn() ?: 0);

        if ($gruLeads > 0) {
            $stmt = $pdo->prepare(
                'SELECT USU_CODIGO AS codigo, USU_NOME AS nome FROM USUARIOS WHERE GRU_CODIGO = ? ORDER BY USU_NOME'
            );
            $stmt->execute([$gruLeads]);
        } else {
            $stmt = $pdo->query('SELECT USU_CODIGO AS codigo, USU_NOME AS nome FROM USUARIOS ORDER BY USU_NOME');
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn($r) => ['codigo' => (int)$r['codigo'], 'nome' => (string)$r['nome']], $rows);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Verifica se o usuário logado é admin.
 */
function iaIsAdmin(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare('SELECT USU_ADMIN FROM USUARIOS WHERE USU_CODIGO = ?');
        $stmt->execute([usuCodigo()]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function iaFormatCnpj(string $v): string
{
    $digits = preg_replace('/\D/', '', $v);
    if (strlen($digits) === 14) {
        return sprintf('%s.%s.%s/%s-%s',
            substr($digits, 0, 2), substr($digits, 2, 3),
            substr($digits, 5, 3), substr($digits, 8, 4),
            substr($digits, 12, 2)
        );
    }
    return trim($v); // retorna como veio se não tiver 14 dígitos
}

function iaProspDedupeKey(array $lead): string
{
    $nome = iaNormText((string) ($lead['nome_empresa'] ?? ''));
    $site = strtolower((string) ($lead['site'] ?? ''));
    $site = (string) preg_replace('/^https?:\/\/(www\.)?/', '', $site);
    $site = rtrim($site, '/');
    return hash('sha256', $nome . '|' . $site);
}

function iaProspSaveAll(PDO $pdo, array $leads, string $segmentoBusca): array
{
    iaProspEnsureSchema($pdo);
    $novos = 0;
    $duplicados = 0;
    $sql = 'INSERT IGNORE INTO LEADS_PROSPECTADOS
        (cnpj, nome_empresa, site, telefone, email, cidade, uf, segmento, fonte, motivo_relevancia, score, segmento_busca, dedupe_key, contato_compras_nome, contato_compras_meio)
        VALUES (:cnpj, :nome_empresa, :site, :telefone, :email, :cidade, :uf, :segmento, :fonte, :motivo_relevancia, :score, :segmento_busca, :dedupe_key, :contato_compras_nome, :contato_compras_meio)';
    $stmt = $pdo->prepare($sql);
    foreach ($leads as $lead) {
        $stmt->execute([
            'cnpj'                  => iaFormatCnpj((string) ($lead['cnpj'] ?? '')),
            'nome_empresa'          => trim((string) ($lead['nome_empresa'] ?? '')),
            'site'                  => trim((string) ($lead['site'] ?? '')),
            'telefone'              => trim((string) ($lead['telefone'] ?? '')),
            'email'                 => trim((string) ($lead['email'] ?? '')),
            'cidade'                => trim((string) ($lead['cidade'] ?? '')),
            'uf'                    => strtoupper(trim((string) ($lead['uf'] ?? ''))),
            'segmento'              => trim((string) ($lead['segmento'] ?? '')),
            'fonte'                 => trim((string) ($lead['fonte'] ?? '')),
            'motivo_relevancia'     => trim((string) ($lead['motivo_relevancia'] ?? '')),
            'score'                 => (int) ($lead['score'] ?? 0),
            'segmento_busca'        => $segmentoBusca,
            'dedupe_key'            => iaProspDedupeKey($lead),
            'contato_compras_nome'  => trim((string) ($lead['contato_compras_nome'] ?? '')) ?: null,
            'contato_compras_meio'  => trim((string) ($lead['contato_compras_meio'] ?? '')) ?: null,
        ]);
        if ($stmt->rowCount() > 0) $novos++;
        else $duplicados++;
    }
    return ['novos' => $novos, 'duplicados' => $duplicados];
}

function iaProspFilterSaved(PDO $pdo, array $leads): array
{
    iaProspEnsureSchema($pdo);
    if (empty($leads)) return $leads;
    $keys         = array_map('iaProspDedupeKey', $leads);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt         = $pdo->prepare("SELECT dedupe_key FROM LEADS_PROSPECTADOS WHERE dedupe_key IN ($placeholders)");
    $stmt->execute($keys);
    $saved        = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_filter($leads, static fn($l) => !isset($saved[iaProspDedupeKey($l)])));
}

/**
 * Executa busca completa para um preset:
 * 1. Chama OpenAI
 * 2. Compara com carteira ERP (opcional)
 * 3. Filtra já salvos
 * 4. Grava novos no banco
 * Retorna array com resultado detalhado.
 */
function iaRunPreset(string $presetKey, PDO $pdo, array $clientesYz = []): array
{
    $presets = LEADS_PRESETS;
    if (!isset($presets[$presetKey])) {
        return ['ok' => false, 'preset' => $presetKey, 'message' => 'Preset não encontrado.'];
    }

    $form = iaPresetToForm($presets[$presetKey]);

    $resp = iaChamarOpenAI($form);
    if (!$resp['ok']) {
        return ['ok' => false, 'preset' => $presetKey, 'message' => $resp['message'], 'novos' => 0, 'filtrados' => 0];
    }

    $leads = $resp['leads'] ?? [];

    // Compara com ERP Yzidro
    if (!empty($clientesYz)) {
        foreach ($leads as &$lead) {
            $cmp = iaComparar($lead, $clientesYz);
            $lead['status_base']   = $cmp['status'];
            $lead['cliente_match'] = $cmp['cliente'];
        }
        unset($lead);
        if ($form['ocultar_clientes']) {
            $leads = array_values(array_filter($leads, static fn($l) => ($l['status_base'] ?? '') !== 'já é cliente'));
        }
    }

    // Filtra já salvos e grava novos
    $totalAntes = count($leads);
    $leads      = iaProspFilterSaved($pdo, $leads);
    $filtrados  = $totalAntes - count($leads);

    $stats = ['novos' => 0, 'duplicados' => 0];
    if (!empty($leads)) {
        $stats = iaProspSaveAll($pdo, $leads, $form['segmento']);
    }

    return [
        'ok'        => true,
        'preset'    => $presetKey,
        'titulo'    => $presets[$presetKey]['titulo'],
        'leads'     => $leads,
        'novos'     => $stats['novos'],
        'filtrados' => $filtrados,
        'message'   => $stats['novos'] . ' novo(s) gravado(s), ' . $filtrados . ' já existia(m).',
    ];
}
