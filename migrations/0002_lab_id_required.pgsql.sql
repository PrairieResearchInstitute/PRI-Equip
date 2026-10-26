-- 0002: lab_id is required on equipment and export_batches (owner, 2026-10-11, Q-07).
-- The live database gained lab_id through ALTER TABLE, so 0001 left it nullable,
-- whereas includes/schema.php declares it NOT NULL and the isolation rules assume
-- every instrument and export batch belongs to a laboratory. No live row holds a
-- NULL (checked before the switch); SET NOT NULL fails loudly if one ever does.
ALTER TABLE "equipment" ALTER COLUMN "lab_id" SET NOT NULL;
ALTER TABLE "export_batches" ALTER COLUMN "lab_id" SET NOT NULL;
