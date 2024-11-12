# Infonotion

Infonotion
Low-code platform proof-of-concept (crude start for a low-code environment)

# Installation
Needed:
- Memgraph 2.20 - https://memgraph.com/download (... and Docker to run memgraph)
- Php 8.2 or higher 
- Symfony 7 - https://symfony.com/download
- Composer 2.5.8 or similar

Preparations
---
- Install MemGraph

Install these applications and makes sure they are accessible from the command-line:
- Composer
- Php
- Symfony

**Start MemGraph**
- Start MemGraph (see download link)
- Open the MemGraph Lab - ```http://localhost:3000/```
- Click the "Quick connect" button to open a connection (this will open the connection needed to make calls from php)

**Start App**
---
- Clone the repo to a folder
- Open a command-line window in the folder of the cloned repo (in Visual Studio)
- Compose the webapp - ```composer install```
- Run the webapp - ```symfony server:start```

(after that to re-start, only execute the last command here)

Setting up the Database contents
---
- Open MemGraph Lab - ```http://localhost:3000/```
- Go to "Import" and select "Cypherl file import"
- Select the file "starter-questionnaire.cypherl" from the examples folder

This will load the base contents (the meta-meta model and a questionnaire model) to get you started

# Running the application
---
- Open http://127.0.0.1:8000/
*(on Windows: using localhost:8000, can result in slowness due to the DNS resolve)*

# Questionnaire demonstration (work in progress)
Open http://127.0.0.1:8000/questionnaire/ListOfQuestions5edd01130cda05.66170200

# Now what?
This is the setup for your low-code platform. You can add models and data to your graph database.
Make sure you make backups of the database, as it contains all of your stuff.

More examples will be added in time.

Happy coding!
