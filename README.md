# Sexual Child Abuse Reporting Tool (SCART)

**Release 6.3.2 Build 2**

This tool is development by the AbuseIO Foundation and the dutch Child Abuse hotline (EOKM).

AbuseIO is a registered non-profit (ANBI) Foundation in the Netherlands
and as such we are required by law to publish information regarding the
Foundation and how funds are spend. AbuseIO is known under the dutch
RSIN number 855149012 and with the chamber of commerce registration
number 63234955.

EOKM is registered non-profit Foundation in the Netherlands specialized
in assessing and dealing with online child abuse.

**The EOKM Reporting Tool (ERT) is specific version from the AbuseIO Reporting Tool (ART) made for EOKM.**

##Goals and what we do

Our goal is to provide resources that help to combat internet abuse. We will try to achieve this by:

* Create Open Source Software (OSS) and tools based on Open Standards to help manage and detect abuse and help users to resolve internet abuse. This software must be easily installable and for anyone to use, from end users to large network operators or ISP’s
* Represent the interests of the abuse community
* Provide Education on combating internet abuse and promote the usage of resources to combat internet abuse.

See https://abuse.io

##System installation

* install WinterCMS (<= v1.1.9) (https://wintercms.com/docs/setup/installation)
* _up and running wintercms installation with backend_
* cd <root-project>/plugins
* git clone <repro> abuseio
* cd ../
* composer self-update --1 && composer update
* php artisan winter:up
* _scart plugin running_
* _scart settings for user logins_
* _scart settings config (!)_

