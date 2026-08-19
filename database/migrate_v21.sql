-- SFIS v21: add Good/Fair/Bad analysis metadata to Survey options.
-- MySQL 8.0+. Array order is preserved, so existing selected_option_index
-- values continue to reference the same student-visible label.

START TRANSACTION;

UPDATE feedback_questions fq
JOIN (
    SELECT
        source.id,
        CAST(CONCAT(
            '[',
            GROUP_CONCAT(
                JSON_OBJECT(
                    'label', source.label,
                    'category',
                    CASE
                        WHEN source.option_index = 0 THEN 'Good'
                        WHEN source.option_index = 1 THEN 'Fair'
                        ELSE 'Bad'
                    END
                )
                ORDER BY source.option_index SEPARATOR ','
            ),
            ']'
        ) AS JSON) AS migrated_options
    FROM (
        SELECT
            q.id,
            option_rows.ordinality - 1 AS option_index,
            option_rows.label
        FROM feedback_questions q
        JOIN JSON_TABLE(
            q.options_json,
            '$[*]' COLUMNS (
                ordinality FOR ORDINALITY,
                label TEXT PATH '$'
            )
        ) AS option_rows
        WHERE JSON_TYPE(JSON_EXTRACT(q.options_json, '$[0]')) = 'STRING'
    ) AS source
    GROUP BY source.id
) AS migration
    ON migration.id = fq.id
SET fq.options_json = migration.migrated_options;

COMMIT;
