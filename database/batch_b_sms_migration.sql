DELETE s1 FROM scheduled_sms s1
JOIN scheduled_sms s2
  ON  s1.patient_id = s2.patient_id
  AND s1.scheduled_date = s2.scheduled_date
  AND s1.source_module <=> s2.source_module
  AND s1.source_record_id <=> s2.source_record_id
  AND s1.status = 'pending' AND s2.status = 'pending'
  AND s1.id > s2.id;

ALTER TABLE scheduled_sms
  ADD INDEX IF NOT EXISTS idx_followup_lookup (patient_id, source_module, source_record_id, scheduled_date);
