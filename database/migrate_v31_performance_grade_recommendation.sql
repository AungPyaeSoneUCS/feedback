-- SFIS v31: store the editable recommendation on each Performance Grade row.
ALTER TABLE performance_grade_settings
    ADD COLUMN recommendation TEXT NULL AFTER recommendation_key;

UPDATE performance_grade_settings
SET recommendation = CASE grade_code
    WHEN 'excellent' THEN 'Leadership Role အတွက် တာဝန်များပေးရန် အထူးသင့်လျော်သည်။'
    WHEN 'good' THEN 'သတ်မှတ်ထားသော သင်ကြားရေး အရည်အသွေးကို ပြည့်မီကောင်းမွန်သည်။'
    WHEN 'satisfactory' THEN 'လက်ရှိတာဝန်များအတွက် လုံလောက်သော်လည်း ဆက်လက်တိုးတက်ရေးအတွက် လုပ်ဆောင်ရန်လိုအပ်သေးသည်။'
    WHEN 'needs_improvement' THEN 'သင်ကြားရေးလုပ်ငန်းများ တိုးတက်မှုရှိစေရန် Plan များချမှတ်ပြီး Follow-up Observation ပြုလုပ်ရန်လိုအပ်သည်။'
    WHEN 'unsatisfactory' THEN 'အနီးကပ် Support နှင့် Training များပေး၍ ပြန်လည်အကဲဖြတ်မှု ပြုလုပ်ရန်လိုအပ်သည်။'
    ELSE ''
END
WHERE recommendation IS NULL OR recommendation = '';

ALTER TABLE performance_grade_settings
    MODIFY COLUMN recommendation TEXT NOT NULL;
