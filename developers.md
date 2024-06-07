# Credit Commons node (reference implementation)

This package implements the CreditCommonsInterface. Include it with composer if you want to incorporate a credit commons ledger into your PHP application.

## Intent
Develop federable Mutual Credit software that could serve world economy.
The inspiration for this is the [Credit Commons whitepaper](https://creditcommons.net) by [Matthew Slater](https://matslats.net) and [Tim Jenkin](https://en.wikipedia.org/wiki/Tim_Jenkin).

## Vision
The vision here serves the overall [Credit Commons vision](https://gitlab.com/credit-commons/credit-commons-org/blob/master/README.md). Any community can declare a unit of account and use any software they like to keep accounts between members. Note that mutual credit systems require governance and credit/debit limits must be set carefully. The same rules apply when nodes form a group to extend credit to each other on a new trunkward ledger.


## Architectural features.
### Authentication
The current version of the protocol requires that each incoming request includes headers with the account id and a key. Those credentials are then passed to the accountstore for authentication. The accountStore returns a simple authenticated user object or the client is assumed to be anonymous.

### Workflow
Transactions move between states in a workflow path. Each possible transition has access control which depends on the user's relationship to the transaction, e.g. payer, payee, author or admin. Every time the transaction changes state it is written to the ledger and the ledger hashchain updated.

For now, the workflows.json file should be edited by hand.

### Object model
The main objects are the transaction and the account. These have subclasses to cover the cases of transversal transactions (which span many nodes) and remote accounts (which trade and identify themselves using a local account.)
Transaction workflows are inherited from trunkward and localised. The transaction workflow must be accessible to both payer and payee.

### Data model
A transaction on a node may consist of several payments between accounts on that node, and it will progress through a workflow process. All this is stored in two tables, one table for the metadata, and one for each entry. When the transaction changes state the meta data changes but the entries only change the pointer to the new metadata. In addition 3 mysql views are provided to make query building easier.
The payee and payer ids, obtained externally are stored in the ledger.

### Permissions
The API consists of about 10 methods, each with a unique name. The reference implementation is not very flexible about this, but the function which determines access is easy to edit. Each node can determine in the config whether it wishes to expose itself to 4 types of data request from the rest of the tree

### Autocomplete
One REST method is intended to autocomplete accountnames from trunkward nodes, but can also traverse other branches if they permit.

### Pathname system
Remote addresses are prefixed with the node names, and delimited by a '/' just like in a file system. ALso, like in a file system, relative paths are supported.

### Account class hierarchy
In order to relay a transaction accross the tree, the node needs to know its position in the path from twig to twig. It does this by parsing the payer and payee paths and loading them as account objects of different classes.

    Account
      -Remote
        - Branch
          - DownstreamBranch
          - UpstreamBranch
        - Trunkward
          - DownstreamTrunkward
          - UpstreamTrunkward

## Installation
If you have a list of users on another platform you'll need to write your own accountStore class and extend your app to allow configuration of balance limits, otherwise the default accountStore reads from accountStore.json which is kept in the web root.
Similarly the business logic is done with a class that you can write.
Edit workflows.json to your requirements.

###As a REST server
1. Set up a virtualhost
1. Download cc-server into the web root
1. run ```composer install```
1. Configuration UI is at config/index.php or edit node.ini directly.

###As a component in a php app

You'll need a composer.json in your app's root containing at least the following lines.

    {
      "name": "some-name-space/my-application"
      "repositories": [
        {
          "type": "gitlab",
          "url": "git@gitlab.com:credit-commons/cc-node"
        }
      ],
      "require": {
        "credit-commons/cc-node": "@dev",
      }
    }

At the command line:

    $ composer require credit-commons/cc-node: @dev
    $ composer update

Composer will download some respositories into vendor/credit-commons.
See node.ini for configuration.
See more in 'integration', below.

##Integration


### Initiation
If you are not already using composer, put this in your code before any credit commons code is run:

    require_once './vendor/autoload.php';

Before doing any ledger operation, initiate the credit commons object like this:

    $creditcommons = new \CCNode\Node($cc_config);

The $creditcommons object is what reads and writes to the ledger.


### Initiation
Before creating the CCNode\Node object, some application level variables must be created.

You need to initiate the Credit Commons node with a config class, which has all the right property names like in [ConfigFromIni](https://gitlab.com/credit-commons/cc-node/-/blob/master/src/ConfigFromIni.php)

You can just declare a config class and set the values there. The class would look like:

    class MyConfigClass implements CCNode\ConfigInterface {
      function __construct() {
        $this->accountStore = 'MyAccountstoreClassName';
        // etc...
      }
    }
    $cc_config = new MyConfigClass;

Or you can store the config in an ini file and set the config values from that
Copy ```vendor/credit-commons/cc-node/node.ini.example``` to ```node.ini``` in your application root or somewhere convenient.

    class MyConfigClass implements CCNode\ConfigInterface {
      function __construct(array $values) {
        $this->accountStore = $values['account_store'];
        // etc...
      }
    }
    $cc_config = new MyConfigClass(parse_ini_file('node.ini'));

You can find more about what the config values mean in node.ini.

### Saving a transaction
Retrieve the payee/payer users
Use them to make an Entry
User the entry to make a transaction
Validate and save the transaction

You need to convert your transaction into a \CCNode\Transaction object, and then pass it to the $creditcommons to save it to the db. The transaction object checks the types of all fields and throw informative errors to help. The fields are shown in [\CreditCommons\BaseTransaction](https://gitlab.com/credit-commons/cc-php-lib/-/blob/master/src/BaseTransaction.php)
Note that $transaction->entries is an array. Each entry must be prepared as well. The entry properties are shown in [\CreditCommons\Entry](https://gitlab.com/credit-commons/cc-php-lib/-/blob/master/src/Entry.php)

So prepare an stdClass with the transaction properties including an array of stdClass with the entry properties.

There are two classes of transaction, called Transaction and TransversalTransaction. The latter is any transaction that involves at least one Remote account (an account with a url). So you must decide which class and then:
    \CCNode\TransversalTransaction::create($data);


