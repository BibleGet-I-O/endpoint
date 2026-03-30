-- Migration: Add id primary key to counter table and seed the initial row
-- Purpose: Legacy databases may lack an id column on the counter table,
--          but QuoteContext::incrementGoodQueryCount/incrementBadQueryCount
--          use WHERE id = 1. This migration adds the column and ensures
--          a seed row exists.

-- Add the id column if it doesn't exist
DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'counter' AND column_name = 'id'
    ) THEN
        ALTER TABLE counter ADD COLUMN id SMALLINT;
        UPDATE counter SET id = 1;
        ALTER TABLE counter ADD CONSTRAINT counter_pkey PRIMARY KEY (id);
        ALTER TABLE counter ALTER COLUMN id SET NOT NULL;
    END IF;
END $$;

-- Ensure a seed row exists
INSERT INTO counter (id, good, bad) VALUES (1, 0, 0)
ON CONFLICT (id) DO NOTHING;
