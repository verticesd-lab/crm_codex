-- Ativa/cria o terceiro barbeiro da agenda para a empresa minhaloja.
-- Nao altera layout: as telas ja exibem automaticamente todos os barbeiros ativos.

UPDATE barbers b
INNER JOIN companies c ON c.id = b.company_id
SET b.is_active = 1
WHERE c.slug = 'minhaloja'
  AND b.name = 'Barbeiro 3';

INSERT INTO barbers (company_id, name, is_active, created_at)
SELECT c.id, 'Barbeiro 3', 1, NOW()
FROM companies c
WHERE c.slug = 'minhaloja'
  AND NOT EXISTS (
      SELECT 1
      FROM barbers b
      WHERE b.company_id = c.id
        AND b.name = 'Barbeiro 3'
  );
