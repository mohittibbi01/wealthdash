cd\
cd xampp\htdocs\wealthdash
dir /S /B /A:-H | findstr /V "\\\.git\\" | findstr /V "\.\.\.Proect goal" > zzProjectList.txt