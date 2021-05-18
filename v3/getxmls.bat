@echo off

for %%a in (xml/*.xml) do (
	php getoop_single.php xml/%%a
)