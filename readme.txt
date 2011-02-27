This directory contains all of our modifications and customizations to
the Expression Engine code.  Under this main EE_changes directory is a
directory for each version of EE that we've installed.  In each of
those directories are our modified versions of files from those
versions.

Each modification should be thoroughly commented (using the tags BEGIN_THT
and END_THT), so that
at any time we can easily (a) find changes, (b) know exactly what was
changed, and (c) know exactly why we made a change.  Starting with
version 1.2.1, every customization's comment includes the string
'THT', so that we can easily find changes.

The following command will report the THT diffs (assuming proper commenting):
for f in * ; do echo $f; cat $f |  awk '/BEGIN_THT/,/END_THT/ {print;}' ; done

To upgrade to a new version of EE:
-- Upload the new EE code to our account
-- Create a new directory under EE_changes for the new version
-- For each file in the current version of EE_changes, propagate the
   changes forward into the new EE_changes directory
-- Install/upgrade EE, following the EE upgrade instructions
-- Copy the latest EE_changes files to their proper locations.
