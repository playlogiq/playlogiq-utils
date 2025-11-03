🍔 Playlogiq Utils: A comprehensive library of reusable PHP functions and classes to facilitate rapid development and maintain consistency across Playlogiq's codebase.


How to add composer directives:

The instructions for using a custom VCS repository are added to your project's composer.json file, not the GitHub README of the package you want to install.


📦 Adding Composer Directives 


Add the Repository to your Project's composer.json
In the repositories section of the composer.json for the project where you want to install the package, add an entry for the GitHub repository:



    "repositories": [
    
    {
    
    "type": "vcs",
    
    "url": "https://github.com/playlogiq/playlogiq-utils.git"
    
    }
    



"type": "vcs": This tells Composer to treat the URL as a Version Control System repository.

"url": This is the URL of the GitHub repository.


🎷 Require the Package


Once the repository is defined, you can require the package using the package name defined in its own composer.json file (which may differ from the repository name).



    // ... (repositories section above)
    
    "require": {
    
        "playlogiq/playlogiq-utils": "dev-main"
        
    }
    


🏷️ Changing the Branch Reference in composer.json


To require a specific branch X of a package using a VCS repository, you need to use the dev- prefix followed by the branch name as the version constraint in your require block.


The format for requiring a branch is:

"dev-X"

Where X is the branch name.

Example

If the package you are installing has a branch named feature/bugfix-123, you would require it as:



    "repositories": [
    
        {
        
            "type": "vcs",
            
            "url": "https://github.com/playlogiq/playlogiq-utils.git"
            
        }
        
    ],
    
    "require": {
    
        "playlogiq/playlogiq-utils": "dev-feature/bugfix-123"
        
    }
    


When the branch name is non-numeric (like main, develop, or feature/bugfix-123), you must prefix it with dev-.

Composer recognizes this dev- prefix and fetches the code from the specified branch.


Note on Numeric Branches

If your branch name looks like a version (e.g., 1.x or 2.0), Composer uses a slightly different format:

For a branch named 1.x, you would use the constraint "1.x-dev". Composer appends -dev instead of prepending dev-.

For non-version-like names, the dev-X format is the correct one.
