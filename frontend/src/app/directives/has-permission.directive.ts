import { Directive, Input, TemplateRef, ViewContainerRef, OnInit } from '@angular/core';
import { AuthService } from '../services/auth.service';

@Directive({
  selector: '[hasPermission]',
  standalone: true
})
export class HasPermissionDirective implements OnInit {
  @Input('hasPermission') permission: string | string[] = '';
  private permissions: string[] = [];

  constructor(
    private templateRef: TemplateRef<any>,
    private viewContainer: ViewContainerRef,
    private authService: AuthService
  ) {}

  ngOnInit() {
    if (typeof this.permission === 'string') {
      this.permissions = [this.permission];
    } else {
      this.permissions = this.permission;
    }

    this.updateView();
  }

  private updateView() {
    const user = this.authService.currentUser;
    if (!user) {
      this.viewContainer.clear();
      return;
    }

    // Check if user has any of the required permissions
    const hasPermission = this.permissions.some(permission => {
      // This is a simplified check. In a real application, you would want to
      // make an API call to check the actual permissions
      return user.roles.includes(permission);
    });

    if (hasPermission) {
      this.viewContainer.createEmbeddedView(this.templateRef);
    } else {
      this.viewContainer.clear();
    }
  }
} 