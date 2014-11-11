SELECT  SQL_CALC_FOUND_ROWS  
a.*,  
c.name as name,  c.surname as surname,  MAX(a.id) as max_id  
FROM accreditation as a  
LEFT JOIN contact c ON c.id = a.contact_id AND c.client_id= a.client_id  
LEFT JOIN company cc ON cc.id=c.company_id AND cc.client_id= a.client_id  
LEFT JOIN accreditation_to_event aev ON aev.accreditation_id=a.id AND aev.project_id=a.project_id AND aev.client_id= a.client_id  
LEFT JOIN project_co_function pcf ON a.project_co_function_id = pcf.id AND pcf.client_id= a.client_id 
WHERE  
(a.client_id=4 AND  COALESCE(a.copied_from, 0)=0  
AND a.accreditation_status IN(0,1,2)  
AND pcf.co_function_id IN(271,250)  
AND a.project_id=28  
AND a.list_id=0
)
OR
(
a.id IN (
SELECT a2.copied_from 
from accreditation a2
where
a2.copied_from = a.id
AND
a2.accreditation_status=0
AND
a2.project_id=28
GROUP BY a2.copied_from
HAVING count(a2.id) > 0
)

)   
GROUP BY a.parent_id  ORDER BY a.creation_date desc  LIMIT 0, 10  
