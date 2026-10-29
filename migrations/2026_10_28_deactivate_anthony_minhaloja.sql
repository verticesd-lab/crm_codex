-- Retira Anthony da agenda publica da For Men Store.
-- Mantem o registro e os vinculos de agendamentos, servicos e bloqueios.
-- A agenda interna exibe a coluna inativa como vaga para futuro profissional.
-- Para preencher a vaga, cadastre um NOVO barbeiro ativo e mantenha Anthony
-- inativo, preservando a autoria dos atendimentos anteriores.
-- Pode ser executada novamente sem duplicar registros.
UPDATE barbers b
INNER JOIN companies c ON c.id = b.company_id
SET b.is_active = 0
WHERE c.slug = 'minhaloja'
  AND LOWER(TRIM(b.name)) = 'anthony';
