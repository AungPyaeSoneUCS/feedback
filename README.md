# 🎓 UCSH Student Feedback Information System

The **Student Feedback Information System** is a PHP and MySQL project designed for the University of Computer Studies, Hinthada (UCSH). This project is officially owned by ucsh.edu.mm and hosted at `[https://feedback.ucsh.edu.mm](https://feedback.ucsh.edu.mm)`. The source code repository is located at `[https://github.com/AungPyaeSoneUCS/feedback](https://github.com/AungPyaeSoneUCS/feedback)`.

---

## 🚀 Overview & Architecture

This deployment architecture uses a traditional LEMP stack optimized for the university's domain.

* **Web Server & Gateway:** NGINX handles incoming HTTP/HTTPS requests for `feedback.ucsh.edu.mm` and routes them to the application.


* **Application Environment:** PHP 8.3-FPM processes the backend logic.


* **Database Engine:** A native MySQL database stores system data and feedback records.



---

## 💻 Local Development Setup

To run and modify this project locally in your development environment:

1. **Clone the repository:**
```bash
git clone https://github.com/AungPyaeSoneUCS/feedback.git
cd feedback

```


2. **Environment Setup:** Move the cloned folder into your local web server's root directory (e.g., `htdocs` for XAMPP, `www` for WAMP).
3. **Database Configuration:**
* Create a local MySQL database named `studentfeedbackintern`.
* Import the provided database `.sql` file into this new database.


4. **Connect Application:** Open `config/db.php` and update the local database credentials (hostname, username, password).

---

## 🛠️ Server Preparation & Prerequisites

Ensure required tools are installed by running:

```bash
sudo apt update && sudo apt install -y curl git nano unzip ufw

```

(Citation for command prerequisites)

---

## 📦 Initial Server Deployment

You can deploy the application using either a direct file upload (Zip) or by cloning directly from the Git repository.

### Option A: Deployment via File Extraction (Copy File)

Deploying the application requires uploading the packaged source code and extracting it directly into the web root.

1. Securely upload your compressed project file and move the archive to the web directory by executing `sudo mv studentfeedbackinformationsystemforucsh.zip /var/www/html/`.


2. Extract the files using the unzip utility via `sudo unzip /var/www/html/studentfeedback.zip -d /var/www/html/studentfeedbackucsh/.`.



### Option B: Deployment via Git Clone (Recommended)

Cloning directly to the server makes future updates much easier.

1. Navigate to the web root:
```bash
cd /var/www/html/

```


2. Clone the repository directly into the target folder:
```bash
sudo git clone https://github.com/AungPyaeSoneUCS/feedback.git studentfeedbackucsh

```



---

## 🗄️ Database Configuration

To prevent fatal `mysqli_sql_exception` access denial errors, the application must connect using a dedicated MySQL user rather than the system root account.

1. Log into the database shell (`sudo mysql`) and create the user: `CREATE USER 'sfis_user'@'localhost' IDENTIFIED BY 'YourStrongPassword';`.


2. Grant necessary permissions to the application database: `GRANT ALL PRIVILEGES ON studentfeedbackintern.* TO 'sfis_user'@'localhost'; FLUSH PRIVILEGES;`.


3. Update the configuration file using `sudo nano /var/www/html/studentfeedbackucsh/config/db.php`.



---

## 🔐 Permissions & NGINX Configuration

Applying the principle of least privilege ensures security while allowing NGINX and PHP-FPM to read files and accept uploads.

1. Assign ownership of the application directory to the web server: `sudo chown -R www-data:www-data /var/www/html/studentfeedbackucsh`.


2. Set standard access permissions for directories (755 / drwxr-xr-x) and files (644 / -rw-r--r--):


```bash
sudo find /var/www/html/studentfeedbackucsh -type d -exec chmod 755 {} \;
sudo find /var/www/html/studentfeedbackucsh -type f -exec chmod 644 {} \;

```


3. Open the NGINX configuration file: `sudo nano /etc/nginx/sites-available/feedback_ucsh`. Ensure the `server_name` is set to `feedback.ucsh.edu.mm` and the `root` points to your deployed directory.


4. Apply services changes by executing `sudo systemctl restart php8.3-fpm` followed by `sudo systemctl reload nginx`.



---

## 🔄 Server Update Workflow (Git Pull)

When new changes are pushed to the GitHub repository, use this workflow to seamlessly upgrade the live server.

1. **Navigate to the application directory:**
```bash
cd /var/www/html/studentfeedbackucsh

```


2. **Pull the latest updates:**
```bash
sudo git pull origin main

```


*(Troubleshooting: If local file modifications block the pull, run `sudo git fetch --all` and `sudo git reset --hard origin/main` to force sync with the remote repository).*
3. **Reset Permissions:** Newly pulled files may inherit root ownership if pulled via `sudo`. Always re-apply safe permissions:
```bash
sudo chown -R www-data:www-data /var/www/html/studentfeedbackucsh

```



---

## ✅ Verification & Monitoring

Verify the deployment by monitoring real-time errors via `sudo tail -f /var/log/nginx/feedback_error.log` while navigating to `[https://feedback.ucsh.edu.mm/admin/](https://feedback.ucsh.edu.mm/admin/)`.

If you encounter 500 Internal Server Errors, check the NGINX error log or the database connection credentials in `config/db.php`.

---

*Developed and maintained by the University of Computer Studies, Hinthada.*
