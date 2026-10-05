<?php
/**
 * Divide o conteúdo do carrinho em volumes (caixas) que a Total Express aceita.
 *
 * ---------------------------------------------------------------------------
 * A REGRA
 * ---------------------------------------------------------------------------
 * Cada volume pode ter no máximo 30 kg e 120 × 80 × 60 cm. Um pedido pode ter
 * vários volumes na mesma nota fiscal. Cotar o pedido como uma caixa só faz
 * a Total Express sumir acima de 30 kg — justo nos pedidos maiores.
 *
 * ---------------------------------------------------------------------------
 * O MÉTODO ("first-fit decreasing")
 * ---------------------------------------------------------------------------
 * 1. Cada unidade do carrinho vira um item (3 × 1 kg = 3 itens de 1 kg).
 * 2. Os itens são ordenados do mais pesado para o mais leve.
 * 3. Cada item entra na primeira caixa onde ainda cabe; se não couber em
 *    nenhuma, abre uma caixa nova.
 *
 * Começar pelos pesados evita sobrar um item de 25 kg sem lugar no fim.
 * Não é o empacotamento perfeito (esse problema é matematicamente difícil),
 * mas é o método padrão da indústria e acerta quase sempre.
 *
 * As medidas da caixa são uma estimativa (ver medidas()): grande o bastante
 * para o maior item e com volume interno suficiente para todos.
 *
 * Esta classe não usa banco nem rede: recebe números e devolve números. Por
 * isso pode ser testada sozinha.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CVTE_Empacotador
{
    /**
     * Teto de unidades por pedido. O empacotamento cresce mais que linearmente
     * com o número de unidades; sem teto, um carrinho com 100 mil unidades de
     * um produto sem controle de estoque prende o servidor por minutos.
     * 2.000 unidades = 2 t em itens de 1 kg: bem acima de qualquer
     * pedido real pela Total Express.
     */
    const MAX_UNIDADES = 2000;

    /**
     * @param array $itens  lista de ['nome','peso' (kg),'dims' => [c,l,a] (cm),'valor' (R$),'qtd']
     * @param array $cfg    configuração (peso_max_volume, dim_max, dim_min)
     * @return array ['ok' => bool, 'erro' => string, 'volumes' => [ ['peso','valor','dims','itens'] ]]
     */
    public static function empacotar(array $itens, array $cfg): array
    {
        $max_peso = (float) $cfg['peso_max_volume'];
        $max_dims = self::ordenar($cfg['dim_max']);
        $folga = (float) ($cfg['folga_caixa_pct'] ?? 0) / 100;

        $total_unidades = array_sum(array_map(static fn($i) => max(0, (int) $i['qtd']), $itens));
        if ($total_unidades > self::MAX_UNIDADES) {
            return self::erro(sprintf('%d unidades no pedido — acima do limite de %d para cotação automática.', $total_unidades, self::MAX_UNIDADES));
        }

        // 1. Uma entrada por unidade, já validando cada uma.
        $unidades = [];
        foreach ($itens as $item) {
            $dims = self::ordenar($item['dims']);
            if ($item['peso'] > $max_peso) {
                return self::erro(sprintf('"%s" pesa %.2f kg — acima do limite de %.0f kg por volume.', $item['nome'], $item['peso'], $max_peso));
            }
            if (!self::cabe($dims, $max_dims)) {
                return self::erro(sprintf('"%s" mede %s cm — maior que o limite de %s cm.', $item['nome'], implode('×', $dims), implode('×', $max_dims)));
            }
            for ($i = 0; $i < (int) $item['qtd']; $i++) {
                $unidades[] = [
                    'nome'  => $item['nome'],
                    'peso'  => (float) $item['peso'],
                    'dims'  => $dims,
                    'cm3'   => $dims[0] * $dims[1] * $dims[2],
                    'valor' => (float) $item['valor'],
                ];
            }
        }
        if (!$unidades) {
            return self::erro('Carrinho vazio.');
        }

        // 2. Mais pesado primeiro (no empate, o mais volumoso).
        usort($unidades, static fn($a, $b) => [$b['peso'], $b['cm3']] <=> [$a['peso'], $a['cm3']]);

        // 3. Primeira caixa onde cabe.
        $caixas = [];
        foreach ($unidades as $u) {
            $colocado = false;
            foreach ($caixas as &$caixa) {
                $teste = $caixa;
                $teste['unidades'][] = $u;
                $teste['peso'] += $u['peso'];
                if ($teste['peso'] <= $max_peso + 1e-9 && self::cabe(self::medidas($teste['unidades'], $folga), $max_dims)) {
                    $caixa = $teste;
                    $colocado = true;
                    break;
                }
            }
            unset($caixa); // boa prática do PHP após foreach por referência

            if (!$colocado) {
                $caixas[] = ['peso' => $u['peso'], 'unidades' => [$u]];
            }
        }

        // 4. Resultado final de cada caixa, com as medidas mínimas aplicadas.
        $min = self::ordenar($cfg['dim_min']);
        $volumes = [];
        foreach ($caixas as $caixa) {
            $d = self::medidas($caixa['unidades'], $folga);
            $volumes[] = [
                'peso'  => round($caixa['peso'], 3),
                'valor' => round(array_sum(array_column($caixa['unidades'], 'valor')), 2),
                'dims'  => [max($d[0], $min[0]), max($d[1], $min[1]), max($d[2], $min[2])],
                'itens' => array_count_values(array_column($caixa['unidades'], 'nome')),
            ];
        }

        return ['ok' => true, 'erro' => '', 'volumes' => $volumes];
    }

    /**
     * Estima as medidas da caixa que comporta um conjunto de itens.
     *
     * A ideia: a caixa precisa (a) ter pelo menos o tamanho do maior item em
     * cada lado e (b) ter volume interno suficiente para todos os itens.
     * Partimos de um cubo com o volume somado e "esticamos" os lados que o
     * maior item exigir.
     *
     * Por que não empilhar tudo sobre a base do maior item? Porque 30 pacotes
     * de 20×15×5 cm virariam uma coluna de 150 cm, acima do limite de 120 cm,
     * quando na vida real cabem numa caixa de uns 36 cm de lado. (Um teste
     * automático pegou exatamente este erro na primeira versão.)
     *
     * Com 2 ou mais itens, o volume somado ganha uma folga: na caixa real
     * sobra espaço entre eles. Um item sozinho usa as próprias medidas
     * cadastradas — sem folga, para não empurrar um item grande para fora
     * do limite.
     *
     * @param array $unidades itens com 'dims' (ordenadas) e 'cm3'.
     * @param float $folga    fração de espaço perdido (0.15 = 15%).
     * @return array três medidas em cm, do maior para o menor lado.
     */
    public static function medidas(array $unidades, float $folga = 0.0): array
    {
        $cm3 = array_sum(array_column($unidades, 'cm3'));
        if (count($unidades) > 1) {
            $cm3 *= 1 + $folga;
        }
        $maior = static fn(int $lado) => max(array_map(static fn($u) => $u['dims'][$lado], $unidades));

        $comp = max($maior(0), $cm3 ** (1 / 3));            // lado do cubo, ou o item mais comprido
        $larg = max($maior(1), sqrt($cm3 / $comp));         // completa a base
        $alt  = max($maior(2), $cm3 / ($comp * $larg));     // altura que fecha o volume

        return self::ordenar(array_map(static fn($d) => (float) ceil($d), [$comp, $larg, $alt]));
    }

    /** Cada lado ordenado cabe no lado correspondente do limite? */
    public static function cabe(array $dims, array $max): bool
    {
        $dims = self::ordenar($dims);
        return $dims[0] <= $max[0] && $dims[1] <= $max[1] && $dims[2] <= $max[2];
    }

    /** Do maior para o menor: assim tanto faz em que ordem o produto foi cadastrado. */
    public static function ordenar(array $dims): array
    {
        $dims = array_map(static fn($d) => (float) $d, array_values($dims));
        rsort($dims);
        return $dims;
    }

    /**
     * Resultado padrão quando o carrinho não pode ir pela Total Express
     * (item pesado ou grande demais, carrinho vazio). Mesmo formato do
     * sucesso, com ok = false e a lista de volumes vazia.
     */
    private static function erro(string $msg): array
    {
        return ['ok' => false, 'erro' => $msg, 'volumes' => []];
    }
}
