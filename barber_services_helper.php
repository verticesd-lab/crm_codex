<?php
/**
 * barber_services_helper.php
 * ─────────────────────────────────────────────────────────────
 * Inclua este arquivo no agenda_helpers.php ou no topo dos
 * arquivos que precisam carregar serviços por barbeiro:
 *
 *   require_once __DIR__ . '/barber_services_helper.php';
 *
 * ─────────────────────────────────────────────────────────────
 */

/**
 * Retorna serviços disponíveis para um barbeiro específico.
 * Aplica overrides individuais sobre o padrão global.
 *
 * @param PDO $pdo
 * @param int $companyId
 * @param int $barberId   — 0 = retorna padrão global sem override
 * @return array  [ ['id','nome','preco','duracao_min','tem_override'], ... ]
 */
function get_services_for_barber(PDO $pdo, int $companyId, int $barberId = 0): array
{
    try {
        if ($barberId > 0) {
            $st = $pdo->prepare("
                SELECT
                    s.id,
                    s.label                                             AS nome,
                    COALESCE(o.preco,       s.price)                   AS preco,
                    COALESCE(o.duracao_min, s.duration_minutes)        AS duracao_min,
                    s.price                                             AS preco_global,
                    s.duration_minutes                                  AS duracao_global,
                    (o.id IS NOT NULL AND o.preco       IS NOT NULL)   AS tem_preco_custom,
                    (o.id IS NOT NULL AND o.duracao_min IS NOT NULL)   AS tem_duracao_custom,
                    COALESCE(o.ativo, 1)                               AS ativo
                FROM services s
                LEFT JOIN barber_service_overrides o
                    ON  o.service_id  = s.id
                    AND o.barber_id   = ?
                    AND o.company_id  = ?
                WHERE s.company_id = ?
                  AND s.is_active   = 1
                  AND COALESCE(o.ativo, 1) = 1
                ORDER BY s.label ASC
            ");
            $st->execute([$barberId, $companyId, $companyId]);
        } else {
            // Sem barbeiro selecionado — retorna padrão global
            $st = $pdo->prepare("
                SELECT
                    id,
                    label              AS nome,
                    price              AS preco,
                    duration_minutes   AS duracao_min,
                    price              AS preco_global,
                    duration_minutes   AS duracao_global,
                    0                  AS tem_preco_custom,
                    0                  AS tem_duracao_custom,
                    1                  AS ativo
                FROM services
                WHERE company_id = ?
                  AND is_active   = 1
                ORDER BY label ASC
            ");
            $st->execute([$companyId]);
        }
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Retorna um único serviço com override do barbeiro aplicado.
 *
 * @param PDO $pdo
 * @param int $companyId
 * @param int $serviceId
 * @param int $barberId
 * @return array|null
 */
function get_service_for_barber(PDO $pdo, int $companyId, int $serviceId, int $barberId = 0): ?array
{
    $all = get_services_for_barber($pdo, $companyId, $barberId);
    foreach ($all as $s) {
        if ((int)$s['id'] === $serviceId) return $s;
    }
    return null;
}

/**
 * Calcula duração total de uma lista de serviços para um barbeiro.
 * Usa overrides quando disponíveis.
 *
 * @param PDO   $pdo
 * @param int   $companyId
 * @param int   $barberId
 * @param array $serviceIds  — array de IDs de serviço
 * @param int   $slotMin     — duração de cada slot em minutos (padrão 30)
 * @return int  número de slots necessários
 */
function calc_slots_for_barber(PDO $pdo, int $companyId, int $barberId, array $serviceIds, int $slotMin = 30): int
{
    if (empty($serviceIds)) return 0;

    $services = get_services_for_barber($pdo, $companyId, $barberId);
    $byId     = [];
    foreach ($services as $s) $byId[(int)$s['id']] = $s;

    $totalMin = 0;
    foreach ($serviceIds as $sid) {
        $sid = (int)$sid;
        if (isset($byId[$sid])) {
            $totalMin += (int)$byId[$sid]['duracao_min'];
        }
    }

    return $slotMin > 0 ? (int)ceil($totalMin / $slotMin) : 0;
}