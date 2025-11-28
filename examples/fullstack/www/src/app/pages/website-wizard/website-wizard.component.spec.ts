import { ComponentFixture, TestBed } from '@angular/core/testing';

import { WebsiteWizardComponent } from './website-wizard.component';

describe('WebsiteWizardComponent', () => {
  let component: WebsiteWizardComponent;
  let fixture: ComponentFixture<WebsiteWizardComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
    imports: [WebsiteWizardComponent]
})
    .compileComponents();

    fixture = TestBed.createComponent(WebsiteWizardComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
