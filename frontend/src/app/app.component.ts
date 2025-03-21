import { Component } from '@angular/core';
import { AuthService } from './services/auth.service';

@Component({
  selector: 'app-root',
  template: `
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark" *ngIf="authService.isAuthenticated()">
      <div class="container">
        <a class="navbar-brand" href="#">Auth System</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
          <ul class="navbar-nav me-auto">
            <li class="nav-item">
              <a class="nav-link" routerLink="/user" routerLinkActive="active">User Dashboard</a>
            </li>
            <li class="nav-item" *hasPermission="'admin'">
              <a class="nav-link" routerLink="/admin" routerLinkActive="active">Admin Dashboard</a>
            </li>
          </ul>
          <ul class="navbar-nav">
            <li class="nav-item">
              <a class="nav-link" href="#" (click)="logout($event)">Logout</a>
            </li>
          </ul>
        </div>
      </div>
    </nav>

    <div class="container mt-4">
      <router-outlet></router-outlet>
    </div>
  `,
  styles: [`
    .navbar {
      margin-bottom: 20px;
    }
    .nav-link {
      cursor: pointer;
    }
    .nav-link.active {
      font-weight: bold;
    }
  `]
})
export class AppComponent {
  constructor(public authService: AuthService) {}

  logout(event: Event) {
    event.preventDefault();
    this.authService.logout();
  }
} 