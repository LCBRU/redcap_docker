# REDCap Docker

## Download and Configuration

1. Download this repository from github:

```
git clone https://github.com/LCBRU/redcap_docker.git
```

2. Download the appropriate REDCap zip file from [Project REDCap](https://community.projectredcap.org/index.html).
3. Rename the downloaded zip file to `redcap.zip` and place it in the `redcap` folder of this repository.
3. make a copy of `example.env` called `.env` and give it appropriate values.

## Instructions for Installing New Instance of REDCap

1. Log into MySQL on the database server and create the database and assign the user by running the command:

```
CREATE DATABASE IF NOT EXISTS {DATABASE_NAME};

CREATE USER '{USERNAME}'@'%' IDENTIFIED WITH mysql_native_password BY '{USERNAME}';
GRANT SELECT, INSERT, UPDATE, DELETE ON {DATABASE_NAME}.* TO '{USERNAME}'@'%';
```

*Replace `{DATABASE_NAME}` and `{USERNAME}` with appropriate values and record them in the `.env` file.*

2. Start the docker container by running the command:

```
docker-compose build && docker-compose up
```

3. Browse to the URL: `{SERVER_NAME}:{PORT}/install.php`, replacing {SERVER_NAME} and {PORT} with appropriate values for your server.
4. Enter values for all the fields and click next.
5. Copy the SQL from the webpage and run it on the MySQL server.
6. After the script has run, follow the instructions on the webpage to visit the *Configuration Check* page.
7. Change the ownership of the upload directory by running the command:

```
docker-compose exec redcap chown -R www-data:www-data /edocs
```

### Settings to Change

1. Change the name and email address of the `site_admin` account to your good self.
2. In `General Configuration > Other system settings` change the value of `Set a universal 'FROM' email address for *all* emails sent from REDCap` to `donotreply@uhl-tr.nhs.uk`
3. In `File Upload Settings > Storage Configuration Settings` change the value of `Local Server File Storage` to `/edocs`

## Instructions for Upgrading Existing Instance of REDCap

