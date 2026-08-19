const fs = require('fs');
let content = fs.readFileSync('c:/wamp64/www/studentfeedbackucsh/includes/landing.php', 'utf8');

content = content.replace(/if \(!isset\(\$loginType\)\) \{[\s\S]*?\$loginType = 'student';\n\}/, "$loginType = 'admin';");

content = content.replace(/require_once __DIR__ \. '\/auth\.php';/g, "require_once __DIR__ . '/../includes/auth.php';");
content = content.replace(/require_once __DIR__ \. '\/functions\.php';/g, "require_once __DIR__ . '/../includes/functions.php';");

content = content.replace(/\$heroDescriptions = \[[\s\S]*?\];\n\$heroDescription = \$heroDescriptions\[\$loginType\] \?\? '';/m, "$heroDescription = $LANG['hero_admin_desc'] ?? 'Admin can review reports and manage feedback efficiently.';");

content = content.replace(/if \(\$loginType === 'student'\) \{[\s\S]*?\} elseif \(\$loginType === 'admin'\) \{[\s\S]*?\}/m, "$badgeText = \"\";\n$showBadge = false;\n$buttonText = $LANG['login_admin_myanmar'] ?? \"အက်ဒမင် အကောင့်ဖြင့် လော့ဂ်အင်ဝင်ရန်\";");

content = content.replace(/<\?php if \(\(\$loginType \?\? ''\) !== 'admin'\): \?>[\s\S]*?<\?php endif; \?>/m, "");

fs.writeFileSync('c:/wamp64/www/studentfeedbackucsh/admin/index.php', content);
console.log('admin/index.php updated successfully');
