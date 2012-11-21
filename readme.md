# Email Post Approval
**Contributors:** travislopes

**Requires at least:** 3.0

**Tested up to:** 3.4.2

### Description
WordPress plugin that provides the ability to review and approve posts for publishing via email. Upon the saving (or creation) of a post where the post status is set to "draft" or "pending publishing," the blog's admin will receive an email containing the post's title, publish date, tags, categories, and the content of the post. At the end of the email, there will be a link to approve the post. Clicking the link will set the post to be published on the set publish date.

### Changelog
* v1.1.2
	* Changed post approval to set post to future instead of publish.
* v1.1.1
	* Made `<!--more-->` tag visible in email 
* v1.1.0
	* Added options page with the ability to set:
		* Email recipient
		* Information shown in email
		* Statuses that trigger email
* v1.0.0
	* Initial release

### Installation
#### Requirements
* WordPress version 3.0 and later (tested at 3.4.2)

#### Installation
1. Unpack the download package.
1. Upload all files to the `/wp-content/plugins/` directory, with folder
1. Activate the plugin through the 'Plugins' menu in WordPress
