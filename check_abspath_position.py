import os
import re

def check_file(filepath):
    try:
        with open(filepath, 'r', encoding='utf-8') as f:
            content = f.read()
    except Exception as e:
        return None
    
    # Simple regex to find use statements and ABSPATH check
    # Note: This is a heuristic.
    
    use_statements = [m.start() for m in re.finditer(r'^\s*use\s+[\w\\]+', content, re.MULTILINE)]
    abspath_check = re.search(r'defined\(\s*[\'"]ABSPATH[\'"]\s*\)', content)
    
    if not use_statements or not abspath_check:
        return None # Not applicable or doesn't have both
        
    abspath_pos = abspath_check.start()
    first_use_pos = use_statements[0]
    
    if abspath_pos < first_use_pos:
        return filepath
    return None

root_dir = 'src'

for dirpath, dirnames, filenames in os.walk(root_dir):
    for filename in filenames:
        if filename.endswith('.php') and filename != 'index.php':
            filepath = os.path.join(dirpath, filename)
            res = check_file(filepath)
            if res:
                print(res)

