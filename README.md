# Docker REDCap fresh

**NB** Use this Docker image once you have succesfully upgraded Redcap using **docker_redcap_upgrade**

## To run

1. Download the REDCap zip file from [Project REDCap](https://community.projectredcap.org/index.html).
2. Copy the `example.env` to be called `.env` and give it appropriate values, including the `REDCAP_VERSION` value for the version of REDCap downloaded above..
3. Amend the file `database.php`.
