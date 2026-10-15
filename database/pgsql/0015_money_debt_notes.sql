-- Debt notes and the card limit stored with the debt, plus available credit on the account.
-- 0014 is the brain map. Available credit is stored only when a caller supplies it;
-- reads compute limit minus balance when the column is null.
-- Full account numbers are not a column. last4 stays four digits.

ALTER TABLE money_debts
    ADD COLUMN notes TEXT,
    ADD COLUMN credit_limit DOUBLE PRECISION;

ALTER TABLE money_debts
    ADD CONSTRAINT money_debts_limit_chk CHECK (credit_limit IS NULL OR credit_limit >= 0);

ALTER TABLE money_accounts
    ADD COLUMN available_credit DOUBLE PRECISION;

ALTER TABLE money_sinking_funds
    ADD COLUMN notes TEXT;
