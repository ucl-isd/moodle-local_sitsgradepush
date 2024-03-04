# SITS Marks Transfer #

This plugin adds a course level dashboard to manage the transfer of Marks from Moodle into SITS

It is designed for re-usability by other Moodle-SITS institutions and is intended as an architectural design proof of concept towards a full set of reusable Moodle-SITS integration plugins.

This does require some development to adopt but the barrier is greatly reduced.

## Getting Started
1) The plugin needs to know which SITS module deliveries relate to a specific course, we get this via our enrolment integration plugin [this interface](https://github.com/ucl-isd/moodle-local_sitsgradepush/blob/main/classes/manager.php#L247)  is logically seperated so it can be swapped out easily & provided by a different plugin, just needs to be wrapped into a site setting (merge requests welcome). 
2) SITS APIs - SITS has an API framework called [Stutalk](https://www.mysits.com/mysits/sits107/107manuals/index.htm?https://www.mysits.com/mysits/sits107/107manuals/mensys/02super/22stutalk/03st2/00toc.htm) which we called directly during the initial development phase, however at UCL we have an Enterprise API Management layer which allows for internal reusability & standardisation, we implemented these API clients as sub-plugins so that if you also need to use your own institutional bespoke API framework, you can just develop the API client without reinventing the rest of the wheel. The stutalk apiclient needs to implement the [getstudents](https://github.com/ucl-isd/moodle-local_sitsgradepush/blob/main/apiclients/easikit/classes/requests/getstudents.php) request.
3) Stutalk APIs. We can probably share some of the stutalk endpoints that were built, they just don't lend themselves to being in git.
4) As an additional safety, we prevent the configuration of marks transfer for previous academic years. This ends up calling our [Lifecycle](https://github.com/ucl-isd/moodle-block_lifecycle) plugin to find out the [end of the late assessment](https://github.com/ucl-isd/moodle-local_sitsgradepush/blob/main/classes/manager.php#L1057-L1059) period. This could easily be made optional.


## Roadmap
We are just at the initial launch stage, but are expecting to implement the following features in the coming months:
- Select a Gradebook [grade item](https://docs.moodle.org/403/en/Grade_items) (covering LTIs, etc) and [grade category](https://docs.moodle.org/403/en/Grade_categories)
- resits, retakes, repeats, re-assessments, etc
- Late / non-submission reporting
- Automatically populate extra time / deadline extensions for [SORAs](https://www.ucl.ac.uk/students/support-and-wellbeing/disability-support/reasonable-adjustments-your-assessments) and [ECs](https://www.ucl.ac.uk/academic-manual/chapters/chapter-2-student-support-framework/2-short-term-illness-and-other-extenuating)
- Select the marks from a specific part of a Turnitin Assignment with multiple parts
- Combining the source of the marks from multiple Turnitin assignments where these are used to handle seperate deadlines & multiple markers 
