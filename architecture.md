# The Credit Commons Reference Implementation.

Elsewhere we describe the fundamental requirements for an instantiation of the [Credit Commons Protocol](Accounting_TradeEngine_Fundamentals.md) - a tree of nested ledgers, built and interacting on the basis of the Protocol, forming a globally scalable exchange network of trust-scale networks.

Here, we outline the architecture of this reference implementation which provides a ledger via the API.

This implementation is not intended as, but can be deployed as a full application in itself. Normally a credit commons node would be a component of a larger trading or social networking platform, providing a richer trading or community experience than bare accounting. That is why this reference implementation contains only the bare minimum of authentication code and access control.

[Outline of the Transaction process](#outline-of-the-transaction-process)

 - [Workflow](#workflow)

 - [Initiating a transaction](#create-initiate-a-transaction)

 - [Build & Validate - making sure a transaction is possible, and appending any relevant fees](#build-validate-phase)

 - [Approval - ask the Creator to 'press go' for the Transaction as Validated](#approval-phase)

 - [Changing state, and workflow](#workflow-transitions)

 - [Example of transaction replication](#example-of-transaction replication)

[Microservice architecture](#microservice-architecture)

- [Authentication](#authentication)


[Future Implementation](#future-implementation)

- [Growing the Tree](#growing-the-tree)

- [Governance service](#governance-service)

   - [Governance service API calls](#governance-service-api-calls)

## Terminology
  - upstream / downstream is how we talk about a chain of requests/responses accross the tree. The requests go downstream, and the responses come back upstream.
  - trunkward / leafward refers to nodes, relative to the current node. A request to a leafward node, whose name must be known, is sent directly leafward, but if the name is not known, the request is sent to the trunkward node.

## Outline of the Transaction process

The system allows for accounting records - [Transactions](Accounting_TradeEngine_Fundamentals.md#transactions) - to be created in appropriate [Nodes](Accounting_TradeEngine_Fundamentals.md#nodes), within an arbitrarily large [Tree](Accounting_TradeEngine_Fundamentals.md#tree-of-ledgers) of nodes.
See the accompanying [presentation](transaction-walkthrough.odp) for a more visual, almost animated overview.

### Workflow

The workflow object determines the sequence of states a transaction can pass though, and which parties to the transaction (payer, payee, admin) can execute each state transition.

The states and transitions involved in the two provided workflows are as follows

(state: **bold**, transition, *italic*):

null > *Create* > **initiated** > *Validate* > **validated** > *Review* > **pending** > *Sign* > **completed**

Two additional states are required to allow for Timeout and Reversal of a Transaction.

**Validated**/**Pending**/**Completed** > *Erase* > **erased**

**Validated**/**Pending**/**Completed** > *Expire* > **timed_out**

![Visualised example of a workflow](images/Transaction_States_and_Transitions.png)

For a transaction to span multiple nodes, each node must support its workflow. Currently therefore, workflows are inheritied from all trunkward nodes and can be defined locally.

### Create - initiate a Transaction

A NewTransaction object (payer, payee, quant, description, type) is created by the client and sent to its node. This structured object is a simplified transaction with only one entry, intended to be easy for client developers. When the node receives it, it converts it to a normal transaction object, which can be thought of as a header and a list of entries.

### Build/Validate phase
Transaction Entries are categorised as **Main**, which is the one originally passed by the client, or as **Additional**, which means it was added automatically. Every transaction has one main entries and zero or more additional entries.
The transaction is passed to the business logic service which returns any additional entries to be appended. Entries can involve either the upstream or downstream account.

The transaction including only non-local entries is then passed downstream, and any new entries are received back, already validated by every node downstream. The node then validates the transaction, with all its entries, writes the validated transaction to the temp file and passes back upstream any new entries which involve the upstream account.

### Approval phase
If the client is upstream, the whole transaction is returned to the client, along with the actions, appended by the workflow class.
The client, having seen all the dependent transactions, clicks a confirm button, and the first workflow state change is relayed accross the ledger. The most remote node writes the transaction first, adding it to the hash chain, and so on back upstream until the client sees that the transaction is in, say, the 'pending' state, awaiting the payee's approval.

### Change State - workflow transitions.
Whenever the transaction is returned to a client for display, any workflow transitions that that account may perform on the transaction are passed along with the transaction for the client to render as a button or link.

When a transaction changes its workflow state, the headers are rewritten in the transaction table but the entries in the entries table remain unchanged. The hashchain is then updated.


## Example of transaction replication.
In the following example, a transaction replicates accross 3 ledgers child1, parent, and child2, with each ledger adding a fee (into its fees account) for each party to the transaction. The original entry and two for each ledger are numbered 1-7. Two entries, (numbered 3 and 6) are local to their own child ledger. Notice how the names of the parties to the transaction change on each ledger.

### parent/child1/alice pays parent/child2/bob

#### child1
  - 1 +alice -> Trunkward (primary transaction)
  - 2 +parent -> fees (child1 tax on bob)
  - 3 +Alice -> fees (child1 tax on alice) LOCAL

#### parent
  - 1 child1 -> child 2
  - 2 Child2 -> child 1
  - 4 +Child 2 -> fees (parent tax on bob)
  - 5 +child 1 -> fees (parent tax on alice)

#### child2
  - 1 Trunkward -> bob
  - 2 bob -> Trunkward
  - 4 bob -> Trunkward
  - 6 +bob -> fees (child2 tax on bob) LOCAL
  - 7 +Trunkward -> fees (Child2 tax on alice)

#### parent
  - 1 child1 -> child 2
  - 2 Child2 -> child 1
  - 4 Child 2 -> fees
  - 5 child 1 -> fees
  - 7 Child1 -> child 2

####child1
  - 1 alice -> Trunkward
  - 2 Trunkward -> fees
  - 3 Alice -> fees
  - 5 Alice -> Trunkward
  - 7 Trunkward -> fees

Note that every ledger ONLY responds to the 1st transaction - cascading fees are not allowed because the build phase could potentially never finish! Not all entries are visible on all ledgers. The parent tax on child2 is not visible on child1 and the child1 tax on alice is not visible to any other ledger.

## Sub-Services.
If the credit commons is implemented as a web-service, it depends on two sub-services. These are separated out to facilitate customisation.
The main service is called cc-node, it builds and validates and stores transactions, parses relative and absolute account paths, and routes the requests to other nodes.
Then there are two sub-services:

### AccountStore
Retrieves the account names and balance limits for each account. The cc-node itself is not responsible for creating and managing accounts, or the balance limits associated with each account, but calls on the accountStore for this. The accountStore can be a rest service or a PHP class. This is so that cc-node can be integrated with other applications that maintain their own lists of users. The other application must implement about 3 simple endpoints and return a simple user json object.

### Business Logic
Business logic is the term we use for adding entries to a transaction such as transaction fees. Again this can be implemented as a REST service or a included class - it has only one method.
The method is passed the transaction and returns any new entries it would add. The added transactions should involve the remote payer or payee, and any account on the current node.

More subservices are under consideration.

- Notification queue service
- Governance service which handles deliberation and config changes
- Transaction storage service would make it easier to integrate with different backends, such as blockchains.

### Authentication
The reference implementation doesn't specify security. The protocol defines how authentication is done using http headers called cc-user and cc-auth. Where cc-user refers to a normal account, cc-auth is a normal password, which is authenticated via the AccountStore. Where cc-user is a remote account, cc-auth is the latest hash in the hashchain for that account.

## Future Implementation

### Workflows
However this is a bit cumbersome because it means different workflows are available depending where in the tree the counterparty is. This is only a problem for deeper trees, which we don't have yet. A cleaner option might be to include the workflow in the transaction object, and allow each node involved in the transaction to reject the transaction if it doesn't like the workflow for whatever reason. In this case workflows could be created almost ad hoc and nodes would have to permanently store a workflow, or reference to it, for every transaction workflows.

### Growing the Tree
The process of setting up new nodes and connecting to existing nodes remote nodes involves the trunkward and leafward nodes creating accounts with each other, which is fiddly and automatable. 
Nodes should never allow connections from anonymous nodes.

### Time window for approval
Before the transaction is approved it can be deleted entirely. the ledgers need to agree how long the transaction will be kept if not approved before being cleaned up.
This is necessary because there may be some time delay after Validation before a human user completes the Review. Balances and transactions fees might change during this period - which could either invalidate the Transaction or change its cost. The Creator needs to be given a period of confidence as to both of these. Each Ledger will publish a Time Window number in milliseconds.

### HTTP Timeout
Since very remote transactions may have to traverse many ledgers, the timeouts should be determined sensitively. More local operations would be expected to happen more quickly.

### Governance service
This service sets the exchange rate with the parent node. It supports whatever human processes are needed for deliberation, voting, etc. The client would connect to this service to propose policy changes, and vote. We can't build this in MVP and policies will run as php scripts directly on endpoints.
The account balances are also determined by governance, but they are set outside cc-node. the AccountStore has no provision for writing, and cc-node has nowhere to store metadata about accounts.

### Next version
The current implementation attempts to process each transaction in a single request/response cycle, and could get into a mess if any server was down, especially with the hash chain.
The next version needs to separate the request and response into separate calls, to be surer that all messages were received, and to ensure that transactions are written in the correct order, which is critical once there is a significant volume of traffic.
