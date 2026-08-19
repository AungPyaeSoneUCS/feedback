# 🎓 UCSH Student Feedback Information System

The **Student Feedback Information System** is a PHP and MySQL project designed for the University of Computer Studies, Hinthada (UCSH). This project is officially owned by ucsh.edu.mm and hosted at `[https://feedback.ucsh.edu.mm](https://feedback.ucsh.edu.mm)`. The source code repository is located at `[https://github.com/AungPyaeSoneUCS/feedback](https://github.com/AungPyaeSoneUCS/feedback)`.

---

## 🚀 Overview & Architecture

This deployment architecture uses a traditional LEMP stack optimized for the university's domain.

* **Web Server & Gateway:** NGINX handles incoming HTTP/HTTPS requests for `feedback.ucsh.edu.mm` and routes them to the application.


* **Application Environment:** PHP 8.3-FPM processes the backend logic.


* **Database Engine:** A native MySQL database stores system data and feedback records.



---

## 🛠️ Deployment & Extraction

Ensure required tools are installed by running `sudo apt update && sudo apt install -y curl git nano unzip ufw`. Deploying the application requires uploading the packaged source code and extracting it directly into the web root.

* Securely upload your compressed project file and move the archive to the web directory by executing `sudo mv studentfeedbackinformationsystemforucsh.zip /var/www/html/`.


* Extract the files using the unzip utility via `sudo unzip /var/www/html/studentfeedback.zip -d /var/www/html/studentfeedbackucsh/.`.



---

## 🗄️ Database Configuration

To prevent fatal `mysqli_sql_exception` access denial errors, the application must connect using a dedicated MySQL user rather than the system root account.

* Log into the database shell (`sudo mysql`) and create the user: `CREATE USER 'sfis_user'@'localhost' IDENTIFIED BY 'YourStrongPassword';`.


* Grant necessary permissions to the application database: `GRANT ALL PRIVILEGES ON studentfeedbackintern.* TO 'sfis_user'@'localhost'; FLUSH PRIVILEGES;`.


* Update the configuration file using `sudo nano /var/www/html/studentfeedbackucsh/config/db.php`.



---

## 🔐 Permissions & Verification

Applying the principle of least privilege ensures security while allowing NGINX and PHP-FPM to read files and accept uploads.

* Assign ownership of the application directory to the web server (`sudo chown -R www-data:www-data /var/www/html/studentfeedbackucsh`), and set standard access permissions for directories (755 / drwxr-xr-x) and files (644 / -rw-r--r--).


* Apply services changes by executing `sudo systemctl restart php8.3-fpm` followed by `sudo systemctl reload nginx`.


* Open the NGINX configuration file (`sudo nano /etc/nginx/sites-available/feedback_ucsh`), then verify the deployment by monitoring real-time errors via `sudo tail -f /var/log/nginx/feedback_error.log` while navigating to `[https://feedback.ucsh.edu.mm/admin/](https://feedback.ucsh.edu.mm/admin/)`.


