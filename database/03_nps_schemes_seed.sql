-- ============================================================
-- WealthDash — NPS Schemes Seed Data (Fixed)
-- TRUNCATE hata diya — FK constraint error aata tha
-- INSERT IGNORE use karo — duplicate scheme_code skip hoga
-- phpMyAdmin → wealthdash DB → SQL tab → paste → Go
-- ============================================================

INSERT IGNORE INTO `nps_schemes` (`pfm_name`, `scheme_name`, `scheme_code`, `tier`, `asset_class`) VALUES
-- SBI Pension Funds
('SBI Pension Funds', 'SBI Pension Fund - Scheme E - Tier I', 'SBI_E_T1', 'tier1', 'E'),
('SBI Pension Funds', 'SBI Pension Fund - Scheme C - Tier I', 'SBI_C_T1', 'tier1', 'C'),
('SBI Pension Funds', 'SBI Pension Fund - Scheme G - Tier I', 'SBI_G_T1', 'tier1', 'G'),
('SBI Pension Funds', 'SBI Pension Fund - Scheme A - Tier I', 'SBI_A_T1', 'tier1', 'A'),
('SBI Pension Funds', 'SBI Pension Fund - Scheme E - Tier II', 'SBI_E_T2', 'tier2', 'E'),
('SBI Pension Funds', 'SBI Pension Fund - Scheme C - Tier II', 'SBI_C_T2', 'tier2', 'C'),
('SBI Pension Funds', 'SBI Pension Fund - Scheme G - Tier II', 'SBI_G_T2', 'tier2', 'G'),
-- LIC Pension Fund
('LIC Pension Fund', 'LIC Pension Fund - Scheme E - Tier I', 'LIC_E_T1', 'tier1', 'E'),
('LIC Pension Fund', 'LIC Pension Fund - Scheme C - Tier I', 'LIC_C_T1', 'tier1', 'C'),
('LIC Pension Fund', 'LIC Pension Fund - Scheme G - Tier I', 'LIC_G_T1', 'tier1', 'G'),
('LIC Pension Fund', 'LIC Pension Fund - Scheme E - Tier II', 'LIC_E_T2', 'tier2', 'E'),
('LIC Pension Fund', 'LIC Pension Fund - Scheme C - Tier II', 'LIC_C_T2', 'tier2', 'C'),
('LIC Pension Fund', 'LIC Pension Fund - Scheme G - Tier II', 'LIC_G_T2', 'tier2', 'G'),
-- UTI Retirement Solutions
('UTI Retirement Solutions', 'UTI Retirement Solutions - Scheme E - Tier I', 'UTI_E_T1', 'tier1', 'E'),
('UTI Retirement Solutions', 'UTI Retirement Solutions - Scheme C - Tier I', 'UTI_C_T1', 'tier1', 'C'),
('UTI Retirement Solutions', 'UTI Retirement Solutions - Scheme G - Tier I', 'UTI_G_T1', 'tier1', 'G'),
('UTI Retirement Solutions', 'UTI Retirement Solutions - Scheme E - Tier II', 'UTI_E_T2', 'tier2', 'E'),
('UTI Retirement Solutions', 'UTI Retirement Solutions - Scheme C - Tier II', 'UTI_C_T2', 'tier2', 'C'),
('UTI Retirement Solutions', 'UTI Retirement Solutions - Scheme G - Tier II', 'UTI_G_T2', 'tier2', 'G'),
-- HDFC Pension Fund
('HDFC Pension Fund', 'HDFC Pension Fund - Scheme E - Tier I', 'HDFC_E_T1', 'tier1', 'E'),
('HDFC Pension Fund', 'HDFC Pension Fund - Scheme C - Tier I', 'HDFC_C_T1', 'tier1', 'C'),
('HDFC Pension Fund', 'HDFC Pension Fund - Scheme G - Tier I', 'HDFC_G_T1', 'tier1', 'G'),
('HDFC Pension Fund', 'HDFC Pension Fund - Scheme E - Tier II', 'HDFC_E_T2', 'tier2', 'E'),
('HDFC Pension Fund', 'HDFC Pension Fund - Scheme C - Tier II', 'HDFC_C_T2', 'tier2', 'C'),
('HDFC Pension Fund', 'HDFC Pension Fund - Scheme G - Tier II', 'HDFC_G_T2', 'tier2', 'G'),
-- ICICI Prudential Pension Fund
('ICICI Prudential Pension Fund', 'ICICI Pru Pension Fund - Scheme E - Tier I', 'ICICI_E_T1', 'tier1', 'E'),
('ICICI Prudential Pension Fund', 'ICICI Pru Pension Fund - Scheme C - Tier I', 'ICICI_C_T1', 'tier1', 'C'),
('ICICI Prudential Pension Fund', 'ICICI Pru Pension Fund - Scheme G - Tier I', 'ICICI_G_T1', 'tier1', 'G'),
('ICICI Prudential Pension Fund', 'ICICI Pru Pension Fund - Scheme E - Tier II', 'ICICI_E_T2', 'tier2', 'E'),
('ICICI Prudential Pension Fund', 'ICICI Pru Pension Fund - Scheme C - Tier II', 'ICICI_C_T2', 'tier2', 'C'),
('ICICI Prudential Pension Fund', 'ICICI Pru Pension Fund - Scheme G - Tier II', 'ICICI_G_T2', 'tier2', 'G'),
-- Kotak Pension Fund
('Kotak Pension Fund', 'Kotak Pension Fund - Scheme E - Tier I', 'KOTAK_E_T1', 'tier1', 'E'),
('Kotak Pension Fund', 'Kotak Pension Fund - Scheme C - Tier I', 'KOTAK_C_T1', 'tier1', 'C'),
('Kotak Pension Fund', 'Kotak Pension Fund - Scheme G - Tier I', 'KOTAK_G_T1', 'tier1', 'G'),
('Kotak Pension Fund', 'Kotak Pension Fund - Scheme E - Tier II', 'KOTAK_E_T2', 'tier2', 'E'),
('Kotak Pension Fund', 'Kotak Pension Fund - Scheme C - Tier II', 'KOTAK_C_T2', 'tier2', 'C'),
('Kotak Pension Fund', 'Kotak Pension Fund - Scheme G - Tier II', 'KOTAK_G_T2', 'tier2', 'G'),
-- Aditya Birla Sun Life Pension Fund
('Aditya Birla Sun Life Pension Fund', 'ABSL Pension Fund - Scheme E - Tier I', 'ABSL_E_T1', 'tier1', 'E'),
('Aditya Birla Sun Life Pension Fund', 'ABSL Pension Fund - Scheme C - Tier I', 'ABSL_C_T1', 'tier1', 'C'),
('Aditya Birla Sun Life Pension Fund', 'ABSL Pension Fund - Scheme G - Tier I', 'ABSL_G_T1', 'tier1', 'G'),
('Aditya Birla Sun Life Pension Fund', 'ABSL Pension Fund - Scheme E - Tier II', 'ABSL_E_T2', 'tier2', 'E'),
('Aditya Birla Sun Life Pension Fund', 'ABSL Pension Fund - Scheme C - Tier II', 'ABSL_C_T2', 'tier2', 'C'),
('Aditya Birla Sun Life Pension Fund', 'ABSL Pension Fund - Scheme G - Tier II', 'ABSL_G_T2', 'tier2', 'G'),
-- Axis Pension Fund
('Axis Pension Fund', 'Axis Pension Fund - Scheme E - Tier I', 'AXIS_E_T1', 'tier1', 'E'),
('Axis Pension Fund', 'Axis Pension Fund - Scheme C - Tier I', 'AXIS_C_T1', 'tier1', 'C'),
('Axis Pension Fund', 'Axis Pension Fund - Scheme G - Tier I', 'AXIS_G_T1', 'tier1', 'G'),
('Axis Pension Fund', 'Axis Pension Fund - Scheme E - Tier II', 'AXIS_E_T2', 'tier2', 'E'),
('Axis Pension Fund', 'Axis Pension Fund - Scheme C - Tier II', 'AXIS_C_T2', 'tier2', 'C'),
('Axis Pension Fund', 'Axis Pension Fund - Scheme G - Tier II', 'AXIS_G_T2', 'tier2', 'G'),
-- DSP Pension Fund
('DSP Pension Fund', 'DSP Pension Fund - Scheme E - Tier I', 'DSP_E_T1', 'tier1', 'E'),
('DSP Pension Fund', 'DSP Pension Fund - Scheme C - Tier I', 'DSP_C_T1', 'tier1', 'C'),
('DSP Pension Fund', 'DSP Pension Fund - Scheme G - Tier I', 'DSP_G_T1', 'tier1', 'G'),
('DSP Pension Fund', 'DSP Pension Fund - Scheme E - Tier II', 'DSP_E_T2', 'tier2', 'E'),
('DSP Pension Fund', 'DSP Pension Fund - Scheme C - Tier II', 'DSP_C_T2', 'tier2', 'C'),
('DSP Pension Fund', 'DSP Pension Fund - Scheme G - Tier II', 'DSP_G_T2', 'tier2', 'G'),
-- Tata Pension Fund
('Tata Pension Fund', 'Tata Pension Fund - Scheme E - Tier I', 'TATA_E_T1', 'tier1', 'E'),
('Tata Pension Fund', 'Tata Pension Fund - Scheme C - Tier I', 'TATA_C_T1', 'tier1', 'C'),
('Tata Pension Fund', 'Tata Pension Fund - Scheme G - Tier I', 'TATA_G_T1', 'tier1', 'G'),
('Tata Pension Fund', 'Tata Pension Fund - Scheme E - Tier II', 'TATA_E_T2', 'tier2', 'E'),
('Tata Pension Fund', 'Tata Pension Fund - Scheme C - Tier II', 'TATA_C_T2', 'tier2', 'C'),
('Tata Pension Fund', 'Tata Pension Fund - Scheme G - Tier II', 'TATA_G_T2', 'tier2', 'G');

-- Verify — yeh result dikhega: 63 total (35 tier1, 28 tier2)
SELECT COUNT(*) AS total_schemes,
       SUM(tier='tier1') AS tier1_count,
       SUM(tier='tier2') AS tier2_count
FROM nps_schemes;
