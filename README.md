# Credit Commons node (reference implementation)

This package implements the CreditCommonsInterface which can be found

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
This is not a standalone app or a REST server.
To incorporate it in your application, first add this repository to your composer.json

    "repositories": [
       {
           "type": "gitlab",
            "url": "git@gitlab.com:credit-commons/cc-node.git"
       }
    ]

Save and then at the command line:

    $ composer require credit-commons/cc-node
