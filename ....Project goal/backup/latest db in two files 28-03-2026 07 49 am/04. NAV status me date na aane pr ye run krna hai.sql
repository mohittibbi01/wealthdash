UPDATE nav_download_progress p
JOIN funds f ON f.scheme_code = p.scheme_code
JOIN (
    SELECT fund_id, MIN(nav_date) AS min_date
    FROM nav_history
    GROUP BY fund_id
) nh ON nh.fund_id = f.id
SET p.from_date = nh.min_date
WHERE p.from_date IS NULL AND p.status = 'completed';