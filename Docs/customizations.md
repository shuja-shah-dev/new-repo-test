# Customizations for xis.ai Times Portal

- **Next Integration** for folder creation upon Project creation  
- **Switched to Seafile Integration**  
  `createProject` function in `src\Controller\ProjectController.php`

---

- **Restrictions added** for users (not for Super Admin) to prevent creating timesheets in the past  
- **Exception:** Allowed within **20 minutes grace period**  
- **Modified edit function** accordingly  
  `edit` function in `src\Controller\TimesheetAbstractController.php`

---

- **Commented out** project folder creation in cloud  
  `createProject` function in `src\Controller\ProjectController.php`
