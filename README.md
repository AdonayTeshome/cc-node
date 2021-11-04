# Credit Commons reference implementation

Reference implementation of the [Credit Commons protocol](https://gitlab.com/credit-commons-software-stack/cc-php-lib/-/blob/master/docs/credit-commons-openapi-3.0.yml)

## Intent
Develop network software that can serve a fully differentiated world economy of federated Mutual Credit networks.
The inspiration for this is the [Credit Commons whitepaper](http://www.creditcommons.net/) by [Matthew Slater](https://matslats.net/) and [Tim Jenkin](https://en.wikipedia.org/wiki/Tim_Jenkin).

## Vision

The vision here serves [the overall Credit Commons vision, here](https://gitlab.com/credit-commons-software-stack/credit-commons-org/blob/master/README.md).

This project is for the development of a robust, small microservice implementation of a trading and accounting platform for the Credit Commons that is built for federation.

## WHAT WE HAVE
1. A granular specification for the core trading and accounting function
   - [technology agnostic](https://gitlab.com/credit-commons-software-stack/credit-commons-microservices/-/blob/master/docs/Accounting_TradeEngine_Fundamentals.md)
   - [microservice architecture](https://gitlab.com/credit-commons-software-stack/credit-commons-microservices/-/blob/master/docs/Accounting_TradeEngine_microservice_architecture.md)
   - [swagger definitions of microservice API endpoints](https://gitlab.com/credit-commons-software-stack/credit-commons-microservices/-/tree/master/docs/swagger)

## WHAT WE NEED

## Developmental / background docs
These are superseded, but may be worth checking back on in light of controversial or difficult decisions:

 - ["Credit Commons - values and architecture"](https://docs.google.com/document/d/1fz-d8SbLd2-zwA9TswGifhd9AJI-Lt0Geo5wmBIMuCw/edit?usp=sharing) - Matthew's April '19 doc inc some Swagger
 - ["Credit Commons Software Architecture"](https://docs.google.com/document/d/1Me4htdNXv4-1Wj6gXMQUWuJMBkfoqthOozzbilKVIJo/edit?usp=sharing) - Dil's initial analysis / design overview document
 - ["Messaging approach" - ](https://docs.google.com/document/d/1IYbZvm1nf6OeY49mWMcj_dR2DMN0zqEoG32gz0UuIW4/edit?usp=sharing) - exploration of an implementation using chatbots to parse messages.